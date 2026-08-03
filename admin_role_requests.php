<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include "connection.php";
include "language.php";

/*
|--------------------------------------------------------------------------
| Check login
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$admin_id = (int)$_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Check admin permission
|--------------------------------------------------------------------------
*/

$admin_stmt = $conn->prepare("
    SELECT
        id,
        first_name,
        last_name,
        email,
        user_type
    FROM users
    WHERE id = ?
    LIMIT 1
");

if (!$admin_stmt) {
    die("SQL ERROR: " . $conn->error);
}

$admin_stmt->bind_param("i", $admin_id);
$admin_stmt->execute();

$admin_result = $admin_stmt->get_result();
$current_admin = $admin_result->fetch_assoc();

$admin_stmt->close();

if (!$current_admin) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$_SESSION['user_type'] = $current_admin['user_type'];

if ($current_admin['user_type'] !== 'admin') {
    header("Location: dashboard.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| CSRF protection
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];

/*
|--------------------------------------------------------------------------
| Messages
|--------------------------------------------------------------------------
*/

$message = "";
$message_type = "";

if (isset($_GET['success'])) {

    if ($_GET['success'] === 'approved') {
        $message = t('partner_request_approved_successfully');
        $message_type = "success";
    }

    if ($_GET['success'] === 'rejected') {
        $message = t('partner_request_rejected_successfully');
        $message_type = "success";
    }
}

/*
|--------------------------------------------------------------------------
| Approve or reject request
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submitted_token = $_POST['csrf_token'] ?? "";
    $request_id = (int)($_POST['request_id'] ?? 0);
    $action = trim($_POST['action'] ?? "");
    $admin_note = trim($_POST['admin_note'] ?? "");

    if (
        !hash_equals(
            $_SESSION['csrf_token'],
            $submitted_token
        )
    ) {
        $message = t('invalid_security_token_refresh');
        $message_type = "error";

    } elseif ($request_id <= 0) {

        $message = t('invalid_request');
        $message_type = "error";

    } elseif (!in_array($action, ['approve', 'reject'], true)) {

        $message = t('invalid_action');
        $message_type = "error";

    } elseif (
        $action === 'reject' &&
        mb_strlen($admin_note) < 3
    ) {

        $message = t('enter_rejection_reason');
        $message_type = "error";

    } else {

        try {

            $conn->begin_transaction();

            /*
            |--------------------------------------------------------------------------
            | Lock and read the request
            |--------------------------------------------------------------------------
            */

            $request_stmt = $conn->prepare("
    SELECT
        id,
        user_id,
        requested_role,
        payment_status,
        request_status
    FROM role_requests
    WHERE id = ?
    LIMIT 1
    FOR UPDATE
");

            if (!$request_stmt) {
                throw new Exception($conn->error);
            }

            $request_stmt->bind_param("i", $request_id);
            $request_stmt->execute();

            $request_result = $request_stmt->get_result();
            $role_request = $request_result->fetch_assoc();

            $request_stmt->close();

            if (!$role_request) {
                throw new Exception(t('request_not_found'));
            }

            if ($role_request['request_status'] !== 'pending') {
                throw new Exception(
                    t('request_already_reviewed')
                );
            }
            if (
    $action === 'approve' &&
    $role_request['payment_status'] !== 'paid'
) {
    throw new Exception(
        t('request_cannot_be_approved_before_payment')
    );
}

            $allowed_roles = [
                'owner',
                'attraction_owner'
            ];

            if (
                !in_array(
                    $role_request['requested_role'],
                    $allowed_roles,
                    true
                )
            ) {
                throw new Exception(t('requested_role_invalid'));
            }

            $request_user_id = (int)$role_request['user_id'];

            /*
            |--------------------------------------------------------------------------
            | Approve request
            |--------------------------------------------------------------------------
            */

            if ($action === 'approve') {

                $new_role = $role_request['requested_role'];

                $update_user_stmt = $conn->prepare("
                    UPDATE users
                    SET user_type = ?
                    WHERE id = ?
                ");

                if (!$update_user_stmt) {
                    throw new Exception($conn->error);
                }

                $update_user_stmt->bind_param(
                    "si",
                    $new_role,
                    $request_user_id
                );

                if (!$update_user_stmt->execute()) {
                    throw new Exception(
                        t('user_role_update_failed')
                    );
                }

                $update_user_stmt->close();

                $update_request_stmt = $conn->prepare("
                    UPDATE role_requests
                    SET
                        request_status = 'approved',
                        admin_note = ?,
                        reviewed_at = NOW()
                    WHERE id = ?
                ");

                if (!$update_request_stmt) {
                    throw new Exception($conn->error);
                }

                $update_request_stmt->bind_param(
                    "si",
                    $admin_note,
                    $request_id
                );

                if (!$update_request_stmt->execute()) {
                    throw new Exception(
                        t('request_approval_failed')
                    );
                }

                $update_request_stmt->close();

                $conn->commit();

                header(
                    "Location: admin_role_requests.php?success=approved"
                );
                exit();
            }

            /*
            |--------------------------------------------------------------------------
            | Reject request
            |--------------------------------------------------------------------------
            */

            if ($action === 'reject') {

                $update_request_stmt = $conn->prepare("
                    UPDATE role_requests
                    SET
                        request_status = 'rejected',
                        admin_note = ?,
                        reviewed_at = NOW()
                    WHERE id = ?
                ");

                if (!$update_request_stmt) {
                    throw new Exception($conn->error);
                }

                $update_request_stmt->bind_param(
                    "si",
                    $admin_note,
                    $request_id
                );

                if (!$update_request_stmt->execute()) {
                    throw new Exception(
                        t('request_rejection_failed')
                    );
                }

                $update_request_stmt->close();

                $conn->commit();

                header(
                    "Location: admin_role_requests.php?success=rejected"
                );
                exit();
            }

        } catch (Throwable $error) {

            $conn->rollback();

            $message = $error->getMessage();
            $message_type = "error";
        }
    }
}

/*
|--------------------------------------------------------------------------
| Selected status filter
|--------------------------------------------------------------------------
*/

$selected_status = trim($_GET['status'] ?? 'all');

$allowed_statuses = [
    'all',
    'pending',
    'approved',
    'rejected'
];

if (!in_array($selected_status, $allowed_statuses, true)) {
    $selected_status = 'all';
}

/*
|--------------------------------------------------------------------------
| Request statistics
|--------------------------------------------------------------------------
*/

function getAdminRequestCount(
    mysqli $conn,
    string $status = ''
): int {

    if ($status === '') {

        $result = $conn->query("
            SELECT COUNT(*) AS total
            FROM role_requests
        ");

    } else {

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM role_requests
            WHERE request_status = ?
        ");

        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param("s", $status);
        $stmt->execute();

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        $stmt->close();

        return (int)($row['total'] ?? 0);
    }

    if (!$result) {
        return 0;
    }

    $row = $result->fetch_assoc();

    return (int)($row['total'] ?? 0);
}

$total_requests = getAdminRequestCount($conn);
$pending_requests = getAdminRequestCount($conn, 'pending');
$approved_requests = getAdminRequestCount($conn, 'approved');
$rejected_requests = getAdminRequestCount($conn, 'rejected');

/*
|--------------------------------------------------------------------------
| Get requests
|--------------------------------------------------------------------------
*/

if ($selected_status === 'all') {

    $requests_stmt = $conn->prepare("
        SELECT
            rr.id,
            rr.user_id,
            rr.requested_role,
            rr.business_name,
            rr.request_message,
            rr.payment_amount,
            rr.payment_status,
            rr.request_status,
            rr.admin_note,
            rr.created_at,
            rr.reviewed_at,
            u.first_name,
            u.last_name,
            u.email,
            u.phone,
            u.user_type
        FROM role_requests rr
        INNER JOIN users u
            ON rr.user_id = u.id
        ORDER BY
            CASE
                WHEN rr.request_status = 'pending' THEN 1
                WHEN rr.request_status = 'approved' THEN 2
                ELSE 3
            END,
            rr.id DESC
    ");

} else {

    $requests_stmt = $conn->prepare("
        SELECT
            rr.id,
            rr.user_id,
            rr.requested_role,
            rr.business_name,
            rr.request_message,
            rr.payment_amount,
            rr.payment_status,
            rr.request_status,
            rr.admin_note,
            rr.created_at,
            rr.reviewed_at,
            u.first_name,
            u.last_name,
            u.email,
            u.phone,
            u.user_type
        FROM role_requests rr
        INNER JOIN users u
            ON rr.user_id = u.id
        WHERE rr.request_status = ?
        ORDER BY rr.id DESC
    ");

    if ($requests_stmt) {
        $requests_stmt->bind_param("s", $selected_status);
    }
}

if (!$requests_stmt) {
    die("SQL ERROR: " . $conn->error);
}

$requests_stmt->execute();
$requests_result = $requests_stmt->get_result();

/*
|--------------------------------------------------------------------------
| Formatting functions
|--------------------------------------------------------------------------
*/

function formatPartnerRole(string $role): string
{
    return $role === 'owner'
        ? t('cabin_owner')
        : t('attraction_owner');
}

function formatAdminStatus(string $status): string
{
    $statuses = [
        'pending' => t('pending'),
        'approved' => t('approved'),
        'rejected' => t('rejected'),
        'paid' => t('paid'),
        'unpaid' => t('unpaid'),
        'customer' => t('customer'),
        'owner' => t('cabin_owner'),
        'attraction_owner' => t('attraction_owner'),
        'admin' => t('administrator')
    ];

    return $statuses[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

?>

<!DOCTYPE html>
<html lang="<?= ($_SESSION['lang'] ?? 'en') === 'he' ? 'he' : 'en' ?>"
      dir="<?= ($_SESSION['lang'] ?? 'en') === 'he' ? 'rtl' : 'ltr' ?>">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title><?= t('partner_requests') ?> | ZimmerSmart</title>

    <link
        rel="stylesheet"
        href="style.css?v=<?= time() ?>"
    >

</head>

<body>

<?php include "navbar.php"; ?>

<main class="admin-dashboard-page">

    <section class="admin-dashboard-header">

        <div>

            <span class="admin-dashboard-label">
                <?= t('partner_management') ?>
            </span>

            <h1><?= t('partner_requests') ?></h1>

            <p>
                <?= t('partner_requests_description') ?>
            </p>

        </div>

        <div class="admin-profile-summary">

            <div class="admin-profile-avatar">

                <?= htmlspecialchars(
                    strtoupper(
                        mb_substr(
                            $current_admin['first_name'],
                            0,
                            1
                        ) .
                        mb_substr(
                            $current_admin['last_name'],
                            0,
                            1
                        )
                    )
                ) ?>

            </div>

            <div>

                <strong>
                    <?= htmlspecialchars(
                        $current_admin['first_name'] .
                        ' ' .
                        $current_admin['last_name']
                    ) ?>
                </strong>

                <span>
                    <?= htmlspecialchars($current_admin['email']) ?>
                </span>

                <small><?= t('administrator') ?></small>

            </div>

        </div>

    </section>

    <?php if ($message !== ""): ?>

        <div class="partner-alert <?= htmlspecialchars($message_type) ?>">

            <span class="partner-alert-icon">
                <?= $message_type === 'success' ? '✓' : '!' ?>
            </span>

            <span>
                <?= htmlspecialchars($message) ?>
            </span>

        </div>

    <?php endif; ?>

    <section class="admin-stats-grid">

        <article class="admin-stat-card">

            <div class="admin-stat-icon">
                📋
            </div>

            <div>

                <span><?= t('all_requests') ?></span>

                <strong>
                    <?= number_format($total_requests) ?>
                </strong>

                <small><?= t('total_partner_applications') ?></small>

            </div>

        </article>

        <article class="admin-stat-card">

            <div class="admin-stat-icon">
                ⏳
            </div>

            <div>

                <span><?= t('pending') ?></span>

                <strong>
                    <?= number_format($pending_requests) ?>
                </strong>

                <small><?= t('waiting_for_review') ?></small>

            </div>

        </article>

        <article class="admin-stat-card">

            <div class="admin-stat-icon">
                ✅
            </div>

            <div>

                <span><?= t('approved') ?></span>

                <strong>
                    <?= number_format($approved_requests) ?>
                </strong>

                <small><?= t('accepted_partner_requests') ?></small>

            </div>

        </article>

        <article class="admin-stat-card">

            <div class="admin-stat-icon">
                ❌
            </div>

            <div>

                <span><?= t('rejected') ?></span>

                <strong>
                    <?= number_format($rejected_requests) ?>
                </strong>

                <small><?= t('rejected_partner_requests') ?></small>

            </div>

        </article>

    </section>

    <section class="admin-management-section">

        <div class="admin-panel-header">

            <div>

                <span><?= t('filter_requests') ?></span>

                <h2><?= t('application_status') ?></h2>

            </div>

            <a href="admin_dashboard.php">
                <?= t('back_to_dashboard') ?>
            </a>

        </div>

        <div class="admin-request-filters">

            <a
                href="admin_role_requests.php?status=all"
                class="<?= $selected_status === 'all'
                    ? 'active'
                    : '' ?>"
            >
                <?= t('all') ?>
                <span><?= $total_requests ?></span>
            </a>

            <a
                href="admin_role_requests.php?status=pending"
                class="<?= $selected_status === 'pending'
                    ? 'active'
                    : '' ?>"
            >
                <?= t('pending') ?>
                <span><?= $pending_requests ?></span>
            </a>

            <a
                href="admin_role_requests.php?status=approved"
                class="<?= $selected_status === 'approved'
                    ? 'active'
                    : '' ?>"
            >
                <?= t('approved') ?>
                <span><?= $approved_requests ?></span>
            </a>

            <a
                href="admin_role_requests.php?status=rejected"
                class="<?= $selected_status === 'rejected'
                    ? 'active'
                    : '' ?>"
            >
                <?= t('rejected') ?>
                <span><?= $rejected_requests ?></span>
            </a>

        </div>

    </section>

    <section class="admin-role-requests-list">

        <?php if ($requests_result->num_rows > 0): ?>

            <?php while ($request = $requests_result->fetch_assoc()): ?>

                <article class="admin-role-request-card">

                    <div class="admin-role-request-header">

                        <div class="admin-role-request-business">

                            <div class="admin-role-request-icon">

                                <?= $request['requested_role'] === 'owner'
                                    ? '🏡'
                                    : '🎡' ?>

                            </div>

                            <div>

                                <span>
                                    <?= htmlspecialchars(
                                        formatPartnerRole(
                                            $request['requested_role']
                                        )
                                    ) ?>
                                </span>

                                <h2>
                                    <?= htmlspecialchars(
                                        $request['business_name']
                                    ) ?>
                                </h2>

                                <p>
                                    <?= t('request_number') ?> #<?= (int)$request['id'] ?>
                                </p>

                            </div>

                        </div>

                        <span class="admin-status <?= htmlspecialchars(
                            $request['request_status']
                        ) ?>">

                            <?= htmlspecialchars(
                                formatAdminStatus(
                                    $request['request_status']
                                )
                            ) ?>

                        </span>

                    </div>

                    <div class="admin-role-request-grid">

                        <div class="admin-role-request-section">

                            <h3><?= t('applicant_information') ?></h3>

                            <div class="admin-role-info-list">

                                <div>

                                    <span><?= t('full_name') ?></span>

                                    <strong>
                                        <?= htmlspecialchars(
                                            $request['first_name'] .
                                            ' ' .
                                            $request['last_name']
                                        ) ?>
                                    </strong>

                                </div>

                                <div>

                                    <span><?= t('email') ?></span>

                                    <strong>
                                        <?= htmlspecialchars(
                                            $request['email']
                                        ) ?>
                                    </strong>

                                </div>

                                <div>

                                    <span><?= t('phone') ?></span>

                                    <strong>
                                        <?= !empty($request['phone'])
                                            ? htmlspecialchars(
                                                $request['phone']
                                            )
                                            : t('not_provided') ?>
                                    </strong>

                                </div>

                                <div>

                                    <span><?= t('current_role') ?></span>

                                    <strong>
                                        <?= htmlspecialchars(
                                            formatAdminStatus(
                                                $request['user_type']
                                            )
                                        ) ?>
                                    </strong>

                                </div>

                            </div>

                        </div>

                        <div class="admin-role-request-section">

                            <h3><?= t('request_information') ?></h3>

                            <div class="admin-role-info-list">

                                <div>

                                    <span><?= t('requested_role') ?></span>

                                    <strong>
                                        <?= htmlspecialchars(
                                            formatPartnerRole(
                                                $request['requested_role']
                                            )
                                        ) ?>
                                    </strong>

                                </div>

                                <div>

                                    <span><?= t('registration_fee') ?></span>

                                    <strong>
                                        ₪<?= number_format(
                                            (float)$request['payment_amount'],
                                            2
                                        ) ?>
                                    </strong>

                                </div>

                                <div>

                                    <span><?= t('payment_status') ?></span>

                                    <strong>
                                        <?= htmlspecialchars(
                                            formatAdminStatus(
                                                $request['payment_status']
                                            )
                                        ) ?>
                                    </strong>

                                </div>

                                <div>

                                    <span><?= t('submitted') ?></span>

                                    <strong>
                                        <?= date(
                                            'd/m/Y H:i',
                                            strtotime(
                                                $request['created_at']
                                            )
                                        ) ?>
                                    </strong>

                                </div>

                            </div>

                        </div>

                    </div>

                    <div class="admin-role-request-message">

                        <h3><?= t('business_description') ?></h3>

                        <p>
                            <?= nl2br(
                                htmlspecialchars(
                                    $request['request_message']
                                )
                            ) ?>
                        </p>

                    </div>

                    <?php if (
                        $request['request_status'] === 'pending'
                    ): ?>

                        <form
                            method="POST"
                            action="admin_role_requests.php?status=<?= urlencode(
                                $selected_status
                            ) ?>"
                            class="admin-role-request-form"
                        >

                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= htmlspecialchars($csrf_token) ?>"
                            >

                            <input
                                type="hidden"
                                name="request_id"
                                value="<?= (int)$request['id'] ?>"
                            >

                            <div class="admin-role-note-field">

                                <label
                                    for="admin_note_<?= (int)$request['id'] ?>"
                                >
                                    <?= t('administrator_note') ?>
                                </label>

                                <textarea
                                    id="admin_note_<?= (int)$request['id'] ?>"
                                    name="admin_note"
                                    rows="3"
                                    maxlength="1000"
                                    placeholder="<?= htmlspecialchars(t('admin_note_placeholder')) ?>"
                                ></textarea>

                            </div>

                            <div class="admin-role-request-actions">

                                <button
    type="submit"
    name="action"
    value="approve"
    class="admin-approve-button"
    <?= $request['payment_status'] !== 'paid'
        ? 'disabled'
        : '' ?>
    onclick="return confirm(
        <?= json_encode(t('confirm_approve_partner_request')) ?>
    );"
>
    <?= $request['payment_status'] === 'paid'
        ? '✓ ' . t('approve_request')
        : '🔒 ' . t('waiting_for_payment') ?>
</button>

                                <button
    type="submit"
    name="action"
    value="reject"
    class="admin-reject-button"
    onclick='return confirm(<?= json_encode(t("confirm_reject_partner_request")) ?>);'
>
    ✕ <?= t('reject_request') ?>
</button>
                            </div>

                        </form>

                    <?php else: ?>

                        <div class="admin-role-reviewed-box">

                            <div>

                                <span><?= t('reviewed_on') ?></span>

                                <strong>
                                    <?= !empty($request['reviewed_at'])
                                        ? date(
                                            'd/m/Y H:i',
                                            strtotime(
                                                $request['reviewed_at']
                                            )
                                        )
                                        : t('not_available') ?>
                                </strong>

                            </div>

                            <div>

                                <span><?= t('administrator_note') ?></span>

                                <p>
                                    <?= !empty($request['admin_note'])
                                        ? nl2br(
                                            htmlspecialchars(
                                                $request['admin_note']
                                            )
                                        )
                                        : t('no_administrator_note_added') ?>
                                </p>

                            </div>

                        </div>

                    <?php endif; ?>

                </article>

            <?php endwhile; ?>

        <?php else: ?>

            <div class="admin-dashboard-panel admin-empty-state">

                <span>📭</span>

                <h3><?= t('no_requests_found') ?></h3>

                <p>
                    <?= t('no_partner_requests_matching_status') ?>
                </p>

            </div>

        <?php endif; ?>

    </section>

</main>

<script>

document.addEventListener("DOMContentLoaded", function () {

    const rejectButtons = document.querySelectorAll(
        ".admin-reject-button"
    );

    rejectButtons.forEach(function (button) {

        button.addEventListener("click", function (event) {

            const form = button.closest(
                ".admin-role-request-form"
            );

            const note = form.querySelector(
                'textarea[name="admin_note"]'
            );

            if (note.value.trim().length < 3) {

                event.preventDefault();

                alert(
                    <?= json_encode(t('enter_rejection_reason')) ?>
                );

                note.focus();
            }

        });

    });

});

</script>

</body>
</html>

<?php

$requests_stmt->close();
$conn->close();

?>