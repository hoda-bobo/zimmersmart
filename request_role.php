<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include "connection.php";
include "language.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$message = "";
$message_type = "";

/*
|--------------------------------------------------------------------------
| Get current user
|--------------------------------------------------------------------------
*/

$user_stmt = $conn->prepare("
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

if (!$user_stmt) {
    die("SQL ERROR: " . $conn->error);
}

$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();

$user_result = $user_stmt->get_result();
$user = $user_result->fetch_assoc();

$user_stmt->close();

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$_SESSION['user_type'] = $user['user_type'];

/*
|--------------------------------------------------------------------------
| Only customers can submit a partner request
|--------------------------------------------------------------------------
*/

if ($user['user_type'] !== 'customer') {
    header("Location: dashboard.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| Get pending request
|--------------------------------------------------------------------------
*/

$pending_stmt = $conn->prepare("
    SELECT
        id,
        requested_role,
        business_name,
        request_message,
        payment_amount,
        payment_status,
        request_status,
        admin_note,
        created_at
    FROM role_requests
    WHERE user_id = ?
      AND request_status = 'pending'
    ORDER BY id DESC
    LIMIT 1
");

if (!$pending_stmt) {
    die("SQL ERROR: " . $conn->error);
}

$pending_stmt->bind_param("i", $user_id);
$pending_stmt->execute();

$pending_result = $pending_stmt->get_result();
$pending_request = $pending_result->fetch_assoc();

$pending_stmt->close();

/*
|--------------------------------------------------------------------------
| Submit new request
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request'])) {

    $requested_role = trim($_POST['requested_role'] ?? "");
    $business_name = trim($_POST['business_name'] ?? "");
    $request_message = trim($_POST['request_message'] ?? "");

    $allowed_roles = [
        'owner',
        'attraction_owner'
    ];

    if ($pending_request) {

        $message = t('partner_error_pending_request');
        $message_type = "error";

    } elseif (!in_array($requested_role, $allowed_roles, true)) {

        $message = t('partner_error_invalid_type');
        $message_type = "error";

    } elseif ($business_name === "") {

        $message = t('partner_error_business_name_required');
        $message_type = "error";

    } elseif (mb_strlen($business_name) < 2) {

        $message = t('partner_error_business_name_short');
        $message_type = "error";

    } elseif ($request_message === "") {

        $message = t('partner_error_message_required');
        $message_type = "error";

    } elseif (mb_strlen($request_message) < 10) {

        $message = t('partner_error_message_short');
        $message_type = "error";

    } else {

        $payment_amount =
            $requested_role === 'owner'
                ? 500.00
                : 300.00;

        $insert_stmt = $conn->prepare("
            INSERT INTO role_requests
            (
                user_id,
                requested_role,
                business_name,
                request_message,
                payment_amount,
                payment_status,
                request_status
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                'pending',
                'pending'
            )
        ");

        if (!$insert_stmt) {
            die("SQL ERROR: " . $conn->error);
        }

        $insert_stmt->bind_param(
            "isssd",
            $user_id,
            $requested_role,
            $business_name,
            $request_message,
            $payment_amount
        );

        if ($insert_stmt->execute()) {

    $new_request_id = $conn->insert_id;

    $insert_stmt->close();

    header(
        "Location: partner_payment.php?request_id=" .
        $new_request_id
    );

    exit();

} else {

            $message = t('partner_error_submit');
            $message_type = "error";
        }

        $insert_stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Success message
|--------------------------------------------------------------------------
*/

if (isset($_GET['submitted']) && $_GET['submitted'] === '1') {
    $message = t('partner_success_submitted');
    $message_type = "success";
}

/*
|--------------------------------------------------------------------------
| Reload pending request after submission
|--------------------------------------------------------------------------
*/

$pending_stmt = $conn->prepare("
    SELECT
        id,
        requested_role,
        business_name,
        request_message,
        payment_amount,
        payment_status,
        request_status,
        admin_note,
        created_at
    FROM role_requests
    WHERE user_id = ?
      AND request_status = 'pending'
    ORDER BY id DESC
    LIMIT 1
");

if (!$pending_stmt) {
    die("SQL ERROR: " . $conn->error);
}

$pending_stmt->bind_param("i", $user_id);
$pending_stmt->execute();

$pending_result = $pending_stmt->get_result();
$pending_request = $pending_result->fetch_assoc();

$pending_stmt->close();

/*
|--------------------------------------------------------------------------
| Get request history
|--------------------------------------------------------------------------
*/

$history_stmt = $conn->prepare("
    SELECT
        id,
        requested_role,
        business_name,
        payment_amount,
        payment_status,
        request_status,
        admin_note,
        created_at,
        reviewed_at
    FROM role_requests
    WHERE user_id = ?
    ORDER BY id DESC
");

if (!$history_stmt) {
    die("SQL ERROR: " . $conn->error);
}

$history_stmt->bind_param("i", $user_id);
$history_stmt->execute();

$request_history = $history_stmt->get_result();

function formatRoleName(string $role): string
{
    return $role === 'owner'
        ? t('partner_role_cabin_owner')
        : t('partner_role_attraction_owner');
}

function formatStatus(string $status): string
{
    $status_key = 'partner_status_' . strtolower($status);
    return t($status_key);
}

?>

<!DOCTYPE html>
<html lang="<?= current_language() ?>" dir="<?= is_rtl() ? 'rtl' : 'ltr' ?>">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title><?= htmlspecialchars(t('partner_page_title')) ?></title>

    <link
        rel="stylesheet"
        href="style.css?v=<?= time() ?>"
    >

</head>

<body>

<?php include "navbar.php"; ?>

<main class="partner-page">

    <section class="partner-hero">

        <div class="partner-hero-content">

            <span class="partner-badge">
                <?= htmlspecialchars(t('partner_badge')) ?>
            </span>

            <h1>
                <?= htmlspecialchars(t('partner_hero_title')) ?>
            </h1>

            <p>
                <?= htmlspecialchars(t('partner_hero_description')) ?>
            </p>

            <div class="partner-hero-user">

                <div class="partner-user-avatar">
                    <?= htmlspecialchars(
                        strtoupper(
                            mb_substr($user['first_name'], 0, 1) .
                            mb_substr($user['last_name'], 0, 1)
                        )
                    ) ?>
                </div>

                <div>
                    <strong>
                        <?= htmlspecialchars(
                            $user['first_name'] . ' ' . $user['last_name']
                        ) ?>
                    </strong>

                    <span>
                        <?= htmlspecialchars($user['email']) ?>
                    </span>
                </div>

            </div>

        </div>

        <div class="partner-hero-visual">

            <div class="partner-floating-card partner-floating-card-one">
                <span>🏡</span>
                <div>
                    <strong><?= htmlspecialchars(t('partner_more_bookings')) ?></strong>
                    <small><?= htmlspecialchars(t('partner_reach_new_guests')) ?></small>
                </div>
            </div>

            <div class="partner-main-illustration">
                <span>🤝</span>
            </div>

            <div class="partner-floating-card partner-floating-card-two">
                <span>📈</span>
                <div>
                    <strong><?= htmlspecialchars(t('partner_smart_growth')) ?></strong>
                    <small><?= htmlspecialchars(t('partner_manage_easily')) ?></small>
                </div>
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

    <section class="partner-benefits">

        <article class="partner-benefit-card">

            <div class="partner-benefit-icon">
                🌍
            </div>

            <h3><?= htmlspecialchars(t('partner_benefit_reach_title')) ?></h3>

            <p>
                <?= htmlspecialchars(t('partner_benefit_reach_text')) ?>
            </p>

        </article>

        <article class="partner-benefit-card">

            <div class="partner-benefit-icon">
                ⚡
            </div>

            <h3><?= htmlspecialchars(t('partner_benefit_manage_title')) ?></h3>

            <p>
                <?= htmlspecialchars(t('partner_benefit_manage_text')) ?>
            </p>

        </article>

        <article class="partner-benefit-card">

            <div class="partner-benefit-icon">
                💜
            </div>

            <h3><?= htmlspecialchars(t('partner_benefit_support_title')) ?></h3>

            <p>
                <?= htmlspecialchars(t('partner_benefit_support_text')) ?>
            </p>

        </article>

    </section>

    <?php if ($pending_request): ?>

        <section class="partner-pending-card">

            <div class="partner-pending-top">

                <div class="partner-pending-icon">
                    ⏳
                </div>

                <div>

                    <span class="partner-status pending">
                        <?= htmlspecialchars(t('partner_pending_approval')) ?>
                    </span>

                    <h2><?= htmlspecialchars(t('partner_review_title')) ?></h2>

                    <p>
                        <?= htmlspecialchars(t('partner_review_text')) ?>
                    </p>

                </div>

            </div>

            <div class="partner-request-details">

                <div class="partner-detail-item">

                    <span><?= htmlspecialchars(t('partner_type')) ?></span>

                    <strong>
                        <?= htmlspecialchars(
                            formatRoleName($pending_request['requested_role'])
                        ) ?>
                    </strong>

                </div>

                <div class="partner-detail-item">

                    <span><?= htmlspecialchars(t('partner_business_name')) ?></span>

                    <strong>
                        <?= htmlspecialchars($pending_request['business_name']) ?>
                    </strong>

                </div>

                <div class="partner-detail-item">

                    <span><?= htmlspecialchars(t('partner_registration_fee')) ?></span>

                    <strong>
                        ₪<?= number_format(
                            (float)$pending_request['payment_amount'],
                            2
                        ) ?>
                    </strong>

                </div>

                <div class="partner-detail-item">

                    <span><?= htmlspecialchars(t('partner_submitted')) ?></span>

                    <strong>
                        <?= date(
                            'd/m/Y',
                            strtotime($pending_request['created_at'])
                        ) ?>
                    </strong>

                </div>

            </div>

            <div class="partner-pending-note">

                <span>💡</span>

                <p>
                    <?= htmlspecialchars(t('partner_pending_note')) ?>
                </p>

            </div>
            <?php if ($pending_request['payment_status'] !== 'paid'): ?>

    <div class="partner-payment-required-box">

        <div>

            <strong><?= htmlspecialchars(t('partner_payment_required')) ?></strong>

            <p>
                <?= htmlspecialchars(t('partner_payment_required_text')) ?>
            </p>

        </div>

        <a
            href="partner_payment.php?request_id=<?= (int)$pending_request['id'] ?>"
            class="partner-pay-now-button"
        >
            <?= htmlspecialchars(t('partner_pay')) ?> ₪<?= number_format(
                (float)$pending_request['payment_amount'],
                2
            ) ?>
        </a>

    </div>

<?php else: ?>

    <div class="partner-payment-completed-box">

        <span>✓</span>

        <div>

            <strong><?= htmlspecialchars(t('partner_payment_completed')) ?></strong>

            <p>
                <?= htmlspecialchars(t('partner_waiting_admin_approval')) ?>
            </p>

        </div>

    </div>

<?php endif; ?>

        </section>

    <?php else: ?>

        <section class="partner-application-layout">

            <div class="partner-plan-section">

                <div class="partner-section-heading">

                    <span><?= htmlspecialchars(t('partner_step_1')) ?></span>

                    <h2><?= htmlspecialchars(t('partner_choose_type_title')) ?></h2>

                    <p>
                        <?= htmlspecialchars(t('partner_choose_type_text')) ?>
                    </p>

                </div>

                <div class="partner-role-cards">

                    <label class="partner-role-card">

                        <input
                            type="radio"
                            name="role_visual"
                            value="owner"
                            data-target-role="owner"
                        >

                        <div class="partner-role-content">

                            <div class="partner-role-icon">
                                🏡
                            </div>

                            <div class="partner-role-text">

                                <span class="partner-role-tag">
                                    <?= htmlspecialchars(t('partner_accommodation')) ?>
                                </span>

                                <h3><?= htmlspecialchars(t('partner_role_cabin_owner')) ?></h3>

                                <p>
                                    <?= htmlspecialchars(t('partner_cabin_owner_text')) ?>
                                </p>

                                <ul>
                                    <li><?= htmlspecialchars(t('partner_cabin_feature_1')) ?></li>
                                    <li><?= htmlspecialchars(t('partner_cabin_feature_2')) ?></li>
                                    <li><?= htmlspecialchars(t('partner_cabin_feature_3')) ?></li>
                                    <li><?= htmlspecialchars(t('partner_cabin_feature_4')) ?></li>
                                </ul>

                            </div>

                            <div class="partner-role-price">

                                <strong>₪500</strong>
                                <span><?= htmlspecialchars(t('partner_registration_fee_lower')) ?></span>

                            </div>

                            <div class="partner-role-check">
                                ✓
                            </div>

                        </div>

                    </label>

                    <label class="partner-role-card">

                        <input
                            type="radio"
                            name="role_visual"
                            value="attraction_owner"
                            data-target-role="attraction_owner"
                        >

                        <div class="partner-role-content">

                            <div class="partner-role-icon">
                                🎡
                            </div>

                            <div class="partner-role-text">

                                <span class="partner-role-tag">
                                    <?= htmlspecialchars(t('partner_experiences')) ?>
                                </span>

                                <h3><?= htmlspecialchars(t('partner_role_attraction_owner')) ?></h3>

                                <p>
                                    <?= htmlspecialchars(t('partner_attraction_owner_text')) ?>
                                </p>

                                <ul>
                                    <li><?= htmlspecialchars(t('partner_attraction_feature_1')) ?></li>
                                    <li><?= htmlspecialchars(t('partner_attraction_feature_2')) ?></li>
                                    <li><?= htmlspecialchars(t('partner_attraction_feature_3')) ?></li>
                                    <li><?= htmlspecialchars(t('partner_attraction_feature_4')) ?></li>
                                </ul>

                            </div>

                            <div class="partner-role-price">

                                <strong>₪300</strong>
                                <span><?= htmlspecialchars(t('partner_registration_fee_lower')) ?></span>

                            </div>

                            <div class="partner-role-check">
                                ✓
                            </div>

                        </div>

                    </label>

                </div>

            </div>

            <div class="partner-form-section">

                <div class="partner-section-heading">

                    <span><?= htmlspecialchars(t('partner_step_2')) ?></span>

                    <h2><?= htmlspecialchars(t('partner_business_details_title')) ?></h2>

                    <p>
                        <?= htmlspecialchars(t('partner_business_details_text')) ?>
                    </p>

                </div>

                <form
                    method="POST"
                    action="request_role.php"
                    class="partner-form"
                    id="partnerRequestForm"
                >

                    <input
                        type="hidden"
                        name="requested_role"
                        id="requested_role"
                        value="<?= htmlspecialchars(
                            $_POST['requested_role'] ?? ''
                        ) ?>"
                    >

                    <div class="partner-form-group">

                        <label for="business_name">
                            <?= htmlspecialchars(t('partner_business_name')) ?>
                            <span class="required-mark">*</span>
                        </label>

                        <small class="required-text">
                            <?= htmlspecialchars(t('required_field')) ?>
                        </small>

                        <div class="partner-input-wrapper">

                            <span>🏢</span>

                            <input
                                type="text"
                                id="business_name"
                                name="business_name"
                                placeholder="<?= htmlspecialchars(t('partner_business_name_placeholder')) ?>"
                                maxlength="150"
                                required
                                value="<?= htmlspecialchars(
                                    $_POST['business_name'] ?? ''
                                ) ?>"
                            >

                        </div>

                    </div>

                    <div class="partner-form-group">

                        <label for="request_message">
                            <?= htmlspecialchars(t('partner_tell_us_label')) ?>
                            <span class="required-mark">*</span>
                        </label>

                        <small class="required-text">
                            <?= htmlspecialchars(t('required_field')) ?>
                        </small>

                        <textarea
                            id="request_message"
                            name="request_message"
                            rows="6"
                            maxlength="1000"
                            placeholder="<?= htmlspecialchars(
                                t('partner_business_description_placeholder')
                            ) ?>"
                            required
                        ><?= htmlspecialchars(
                            $_POST['request_message'] ?? ''
                        ) ?></textarea>

                        <div class="partner-character-row">

                            <small>
                                <?= htmlspecialchars(t('partner_include_info')) ?>
                            </small>

                            <small id="characterCounter">
                                0 / 1000
                            </small>

                        </div>

                    </div>

                    <div
                        class="partner-selected-summary"
                        id="selectedRoleSummary"
                    >

                        <div>

                            <span><?= htmlspecialchars(t('partner_selected_type')) ?></span>

                            <strong id="selectedRoleName">
                                <?= htmlspecialchars(t('partner_select_type_prompt')) ?>
                            </strong>

                        </div>

                        <div>

                            <span><?= htmlspecialchars(t('partner_registration_fee')) ?></span>

                            <strong id="selectedRolePrice">
                                —
                            </strong>

                        </div>

                    </div>

                    <label class="partner-agreement">

                        <input
                            type="checkbox"
                            required
                        >

                        <span>
                            <?= htmlspecialchars(t('partner_agreement_text')) ?>
                        </span>

                    </label>

                    <button
                        type="submit"
                        name="submit_request"
                        class="partner-submit-button"
                    >

                        <span><?= htmlspecialchars(t('partner_submit_request')) ?></span>
                        <span>→</span>

                    </button>

                    <p class="partner-form-note">
                        <?= htmlspecialchars(t('partner_submit_note')) ?>
                    </p>

                </form>

            </div>

        </section>

    <?php endif; ?>

    <?php if ($request_history->num_rows > 0): ?>

        <section class="partner-history-section">

            <div class="partner-section-heading partner-history-heading">

                <span><?= htmlspecialchars(t('partner_history')) ?></span>

                <h2><?= htmlspecialchars(t('partner_history_title')) ?></h2>

                <p>
                    <?= htmlspecialchars(t('partner_history_text')) ?>
                </p>

            </div>

            <div class="partner-history-list">

                <?php while ($request = $request_history->fetch_assoc()): ?>

                    <article class="partner-history-card">

                        <div class="partner-history-icon">

                            <?= $request['requested_role'] === 'owner'
                                ? '🏡'
                                : '🎡' ?>

                        </div>

                        <div class="partner-history-main">

                            <div class="partner-history-title-row">

                                <div>

                                    <h3>
                                        <?= htmlspecialchars(
                                            $request['business_name']
                                        ) ?>
                                    </h3>

                                    <p>
                                        <?= htmlspecialchars(
                                            formatRoleName(
                                                $request['requested_role']
                                            )
                                        ) ?>
                                    </p>

                                </div>

                                <span class="partner-status <?= htmlspecialchars(
                                    $request['request_status']
                                ) ?>">

                                    <?= htmlspecialchars(
                                        formatStatus(
                                            $request['request_status']
                                        )
                                    ) ?>

                                </span>

                            </div>

                            <div class="partner-history-meta">

                                <span>
                                    <?= htmlspecialchars(t('partner_fee')) ?>:
                                    <strong>
                                        ₪<?= number_format(
                                            (float)$request['payment_amount'],
                                            2
                                        ) ?>
                                    </strong>
                                </span>

                                <span>
                                    <?= htmlspecialchars(t('partner_payment')) ?>:
                                    <strong>
                                        <?= htmlspecialchars(
                                            formatStatus(
                                                $request['payment_status']
                                            )
                                        ) ?>
                                    </strong>
                                </span>

                                <span>
                                    <?= htmlspecialchars(t('partner_submitted')) ?>:
                                    <strong>
                                        <?= date(
                                            'd/m/Y',
                                            strtotime($request['created_at'])
                                        ) ?>
                                    </strong>
                                </span>

                            </div>

                            <?php if (!empty($request['admin_note'])): ?>

                                <div class="partner-admin-note">

                                    <strong><?= htmlspecialchars(t('partner_admin_note')) ?>:</strong>

                                    <p>
                                        <?= nl2br(
                                            htmlspecialchars(
                                                $request['admin_note']
                                            )
                                        ) ?>
                                    </p>

                                </div>

                            <?php endif; ?>

                        </div>

                    </article>

                <?php endwhile; ?>

            </div>

        </section>

    <?php endif; ?>

</main>

<script>

document.addEventListener("DOMContentLoaded", function () {

    const roleCards = document.querySelectorAll(
        'input[name="role_visual"]'
    );

    const hiddenRoleInput = document.getElementById("requested_role");
    const roleName = document.getElementById("selectedRoleName");
    const rolePrice = document.getElementById("selectedRolePrice");

    const messageTextarea = document.getElementById("request_message");
    const characterCounter = document.getElementById("characterCounter");

    const roleData = {
        owner: {
            name: "<?= htmlspecialchars(t('partner_role_cabin_owner')) ?>",
            price: "₪500"
        },
        attraction_owner: {
            name: "<?= htmlspecialchars(t('partner_role_attraction_owner')) ?>",
            price: "₪300"
        }
    };

    function selectRole(roleValue) {

        if (!roleData[roleValue]) {
            return;
        }

        hiddenRoleInput.value = roleValue;
        roleName.textContent = roleData[roleValue].name;
        rolePrice.textContent = roleData[roleValue].price;

        roleCards.forEach(function (radio) {

            radio.checked = radio.value === roleValue;

            const roleCard = radio.closest(".partner-role-card");

            if (radio.checked) {
                roleCard.classList.add("selected");
            } else {
                roleCard.classList.remove("selected");
            }

        });

    }

    roleCards.forEach(function (radio) {

        radio.addEventListener("change", function () {
            selectRole(this.value);
        });

    });

    if (hiddenRoleInput && hiddenRoleInput.value !== "") {
        selectRole(hiddenRoleInput.value);
    }

    if (messageTextarea && characterCounter) {

        function updateCounter() {
            characterCounter.textContent =
                messageTextarea.value.length + " / 1000";
        }

        updateCounter();

        messageTextarea.addEventListener("input", updateCounter);
    }

    const form = document.getElementById("partnerRequestForm");

    if (form) {

        form.addEventListener("submit", function (event) {

            if (hiddenRoleInput.value === "") {

                event.preventDefault();

                alert(<?= json_encode(t('partner_error_choose_type'), JSON_UNESCAPED_UNICODE) ?>);

                document.querySelector(
                    ".partner-role-cards"
                ).scrollIntoView({
                    behavior: "smooth",
                    block: "center"
                });

            }

        });

    }

});

</script>

</body>
</html>

<?php
$history_stmt->close();
$conn->close();
?>


