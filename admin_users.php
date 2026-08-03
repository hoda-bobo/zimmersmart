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
| Check administrator permission
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

    if ($_GET['success'] === 'role_updated') {
        $message = t('user_role_updated_successfully');
        $message_type = "success";
    }
}

/*
|--------------------------------------------------------------------------
| Update user role
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submitted_token = $_POST['csrf_token'] ?? "";
    $action = trim($_POST['action'] ?? "");
    $user_id = (int)($_POST['user_id'] ?? 0);
    $new_role = trim($_POST['new_role'] ?? "");

    $allowed_roles = [
        'customer',
        'owner',
        'attraction_owner',
        'admin'
    ];

    if (
        !hash_equals(
            $_SESSION['csrf_token'],
            $submitted_token
        )
    ) {

        $message = t('invalid_security_token_refresh');
        $message_type = "error";

    } elseif ($action !== 'update_role') {

        $message = t('invalid_action');
        $message_type = "error";

    } elseif ($user_id <= 0) {

        $message = t('invalid_user');
        $message_type = "error";

    } elseif (!in_array($new_role, $allowed_roles, true)) {

        $message = t('invalid_user_role');
        $message_type = "error";

    } elseif (
        $user_id === $admin_id &&
        $new_role !== 'admin'
    ) {

        $message = t('cannot_remove_own_admin_role');
        $message_type = "error";

    } else {

        $check_user_stmt = $conn->prepare("
            SELECT
                id,
                user_type
            FROM users
            WHERE id = ?
            LIMIT 1
        ");

        if (!$check_user_stmt) {
            $message = t('user_could_not_be_checked');
            $message_type = "error";

        } else {

            $check_user_stmt->bind_param("i", $user_id);
            $check_user_stmt->execute();

            $check_user_result = $check_user_stmt->get_result();
            $selected_user = $check_user_result->fetch_assoc();

            $check_user_stmt->close();

            if (!$selected_user) {

                $message = t('selected_user_not_found');
                $message_type = "error";

            } else {

                $update_role_stmt = $conn->prepare("
                    UPDATE users
                    SET user_type = ?
                    WHERE id = ?
                ");

                if (!$update_role_stmt) {

                    $message = t('user_role_update_failed');
                    $message_type = "error";

                } else {

                    $update_role_stmt->bind_param(
                        "si",
                        $new_role,
                        $user_id
                    );

                    if ($update_role_stmt->execute()) {

                        $update_role_stmt->close();

                        header(
                            "Location: admin_users.php?success=role_updated"
                        );
                        exit();

                    } else {

                        $message = t('user_role_update_failed');
                        $message_type = "error";

                        $update_role_stmt->close();
                    }
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? "");
$selected_role = trim($_GET['role'] ?? "all");

$allowed_filter_roles = [
    'all',
    'customer',
    'owner',
    'attraction_owner',
    'admin'
];

if (!in_array($selected_role, $allowed_filter_roles, true)) {
    $selected_role = 'all';
}

/*
|--------------------------------------------------------------------------
| Statistics helper
|--------------------------------------------------------------------------
*/

function getUserCount(
    mysqli $conn,
    string $role = ''
): int {

    if ($role === '') {

        $result = $conn->query("
            SELECT COUNT(*) AS total
            FROM users
        ");

        if (!$result) {
            return 0;
        }

        $row = $result->fetch_assoc();

        return (int)($row['total'] ?? 0);
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM users
        WHERE user_type = ?
    ");

    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param("s", $role);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    $stmt->close();

    return (int)($row['total'] ?? 0);
}

$total_users = getUserCount($conn);
$total_customers = getUserCount($conn, 'customer');
$total_owners = getUserCount($conn, 'owner');
$total_attraction_owners = getUserCount(
    $conn,
    'attraction_owner'
);
$total_admins = getUserCount($conn, 'admin');

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$users_per_page = 10;
$current_page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($current_page - 1) * $users_per_page;

/*
|--------------------------------------------------------------------------
| Count filtered users
|--------------------------------------------------------------------------
*/

$count_query = "
    SELECT COUNT(*) AS total
    FROM users
    WHERE 1 = 1
";

$count_types = "";
$count_values = [];

if ($selected_role !== 'all') {
    $count_query .= " AND user_type = ?";
    $count_types .= "s";
    $count_values[] = $selected_role;
}

if ($search !== "") {

    $count_query .= "
        AND (
            first_name LIKE ?
            OR last_name LIKE ?
            OR email LIKE ?
            OR phone LIKE ?
        )
    ";

    $search_value = "%" . $search . "%";

    $count_types .= "ssss";
    $count_values[] = $search_value;
    $count_values[] = $search_value;
    $count_values[] = $search_value;
    $count_values[] = $search_value;
}

$count_stmt = $conn->prepare($count_query);

if (!$count_stmt) {
    die("SQL ERROR: " . $conn->error);
}

if ($count_types !== "") {
    $count_stmt->bind_param(
        $count_types,
        ...$count_values
    );
}

$count_stmt->execute();

$count_result = $count_stmt->get_result();
$count_row = $count_result->fetch_assoc();

$filtered_users_count = (int)($count_row['total'] ?? 0);

$count_stmt->close();

$total_pages = max(
    1,
    (int)ceil($filtered_users_count / $users_per_page)
);

if ($current_page > $total_pages) {
    $current_page = $total_pages;
    $offset = ($current_page - 1) * $users_per_page;
}

/*
|--------------------------------------------------------------------------
| Load users
|--------------------------------------------------------------------------
*/

$users_query = "
    SELECT
        id,
        first_name,
        last_name,
        email,
        phone,
        user_type
    FROM users
    WHERE 1 = 1
";

$users_types = "";
$users_values = [];

if ($selected_role !== 'all') {
    $users_query .= " AND user_type = ?";
    $users_types .= "s";
    $users_values[] = $selected_role;
}

if ($search !== "") {

    $users_query .= "
        AND (
            first_name LIKE ?
            OR last_name LIKE ?
            OR email LIKE ?
            OR phone LIKE ?
        )
    ";

    $search_value = "%" . $search . "%";

    $users_types .= "ssss";
    $users_values[] = $search_value;
    $users_values[] = $search_value;
    $users_values[] = $search_value;
    $users_values[] = $search_value;
}

$users_query .= "
    ORDER BY id DESC
    LIMIT ? OFFSET ?
";

$users_types .= "ii";
$users_values[] = $users_per_page;
$users_values[] = $offset;

$users_stmt = $conn->prepare($users_query);

if (!$users_stmt) {
    die("SQL ERROR: " . $conn->error);
}

$users_stmt->bind_param(
    $users_types,
    ...$users_values
);

$users_stmt->execute();
$users_result = $users_stmt->get_result();

/*
|--------------------------------------------------------------------------
| Formatting functions
|--------------------------------------------------------------------------
*/

function formatUserRole(string $role): string
{
    $roles = [
        'customer' => t('customer'),
        'owner' => t('cabin_owner'),
        'attraction_owner' => t('attraction_owner'),
        'admin' => t('administrator')
    ];

    return $roles[$role] ?? ucfirst(
        str_replace('_', ' ', $role)
    );
}

function buildUsersPageUrl(
    int $page,
    string $search,
    string $role
): string {

    return "admin_users.php?" . http_build_query([
        'search' => $search,
        'role' => $role,
        'page' => $page
    ]);
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

    <title><?= t('manage_users') ?> | ZimmerSmart</title>

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
                <?= t('user_management') ?>
            </span>

            <h1><?= t('manage_users') ?></h1>

            <p>
                <?= t('manage_users_page_description') ?>
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
                    <?= htmlspecialchars(
                        $current_admin['email']
                    ) ?>
                </span>

                <small><?= t('administrator') ?></small>

            </div>

        </div>

    </section>

    <?php if ($message !== ""): ?>

        <div class="partner-alert <?= htmlspecialchars(
            $message_type
        ) ?>">

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
                👥
            </div>

            <div>

                <span><?= t('all_users') ?></span>

                <strong>
                    <?= number_format($total_users) ?>
                </strong>

                <small><?= t('all_registered_accounts') ?></small>

            </div>

        </article>

        <article class="admin-stat-card">

            <div class="admin-stat-icon">
                👤
            </div>

            <div>

                <span><?= t('customers') ?></span>

                <strong>
                    <?= number_format($total_customers) ?>
                </strong>

                <small><?= t('regular_customer_accounts') ?></small>

            </div>

        </article>

        <article class="admin-stat-card">

            <div class="admin-stat-icon">
                🏡
            </div>

            <div>

                <span><?= t('cabin_owners') ?></span>

                <strong>
                    <?= number_format($total_owners) ?>
                </strong>

                <small><?= t('approved_cabin_owners') ?></small>

            </div>

        </article>

        <article class="admin-stat-card">

            <div class="admin-stat-icon">
                🎡
            </div>

            <div>

                <span><?= t('attraction_owners') ?></span>

                <strong>
                    <?= number_format(
                        $total_attraction_owners
                    ) ?>
                </strong>

                <small><?= t('approved_attraction_owners') ?></small>

            </div>

        </article>

        <article class="admin-stat-card">

            <div class="admin-stat-icon">
                🛡️
            </div>

            <div>

                <span><?= t('administrators') ?></span>

                <strong>
                    <?= number_format($total_admins) ?>
                </strong>

                <small><?= t('administrator_accounts') ?></small>

            </div>

        </article>

    </section>

    <section class="admin-management-section">

        <div class="admin-panel-header">

            <div>

                <span><?= t('find_accounts') ?></span>

                <h2><?= t('search_and_filter_users') ?></h2>

            </div>

            <a href="admin_dashboard.php">
                <?= t('back_to_dashboard') ?>
            </a>

        </div>

        <form
            method="GET"
            action="admin_users.php"
            class="admin-users-filter-form"
        >

            <div class="admin-users-search-field">

                <label for="user_search">
                    <?= t('search_user') ?>
                </label>

                <input
                    type="text"
                    id="user_search"
                    name="search"
                    value="<?= htmlspecialchars($search) ?>"
                    placeholder="<?= htmlspecialchars(t('name_email_or_phone_placeholder')) ?>"
                >

            </div>

            <div class="admin-users-role-filter">

                <label for="role_filter">
                    <?= t('user_role') ?>
                </label>

                <select
                    id="role_filter"
                    name="role"
                >

                    <option
                        value="all"
                        <?= $selected_role === 'all'
                            ? 'selected'
                            : '' ?>
                    >
                        <?= t('all_roles') ?>
                    </option>

                    <option
                        value="customer"
                        <?= $selected_role === 'customer'
                            ? 'selected'
                            : '' ?>
                    >
                        <?= t('customers') ?>
                    </option>

                    <option
                        value="owner"
                        <?= $selected_role === 'owner'
                            ? 'selected'
                            : '' ?>
                    >
                        <?= t('cabin_owners') ?>
                    </option>

                    <option
                        value="attraction_owner"
                        <?= $selected_role === 'attraction_owner'
                            ? 'selected'
                            : '' ?>
                    >
                        <?= t('attraction_owners') ?>
                    </option>

                    <option
                        value="admin"
                        <?= $selected_role === 'admin'
                            ? 'selected'
                            : '' ?>
                    >
                        <?= t('administrators') ?>
                    </option>

                </select>

            </div>

            <button
                type="submit"
                class="admin-users-search-button"
            >
                <?= t('search') ?>
            </button>

            <a
                href="admin_users.php"
                class="admin-users-reset-button"
            >
                <?= t('reset') ?>
            </a>

        </form>

    </section>

    <section class="admin-dashboard-panel admin-users-panel">

        <div class="admin-panel-header">

            <div>

                <span><?= t('registered_accounts') ?></span>

                <h2>
                    <?= t('users') ?>

                    <small>
                        (<?= number_format(
                            $filtered_users_count
                        ) ?>)
                    </small>
                </h2>

            </div>

        </div>

        <?php if ($users_result->num_rows > 0): ?>

            <div class="admin-users-table-wrapper">

                <table class="admin-users-table">

                    <thead>

                        <tr>

                            <th><?= t('user') ?></th>
                            <th><?= t('contact') ?></th>
                            <th><?= t('current_role') ?></th>
                            <th><?= t('change_role') ?></th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php while (
                            $user = $users_result->fetch_assoc()
                        ): ?>

                            <tr>

                                <td>

                                    <div class="admin-users-person">

                                        <div class="admin-user-avatar">

                                            <?= htmlspecialchars(
                                                strtoupper(
                                                    mb_substr(
                                                        $user['first_name'],
                                                        0,
                                                        1
                                                    ) .
                                                    mb_substr(
                                                        $user['last_name'],
                                                        0,
                                                        1
                                                    )
                                                )
                                            ) ?>

                                        </div>

                                        <div>

                                            <strong>
                                                <?= htmlspecialchars(
                                                    $user['first_name'] .
                                                    ' ' .
                                                    $user['last_name']
                                                ) ?>
                                            </strong>

                                            <span>
                                                <?= t('user_number') ?> #<?= (int)$user['id'] ?>
                                            </span>

                                        </div>

                                    </div>

                                </td>

                                <td>

                                    <div class="admin-users-contact">

                                        <strong>
                                            <?= htmlspecialchars(
                                                $user['email']
                                            ) ?>
                                        </strong>

                                        <span>
                                            <?= !empty($user['phone'])
                                                ? htmlspecialchars(
                                                    $user['phone']
                                                )
                                                : t('no_phone_number') ?>
                                        </span>

                                    </div>

                                </td>

                                <td>

                                    <span class="admin-user-role <?= htmlspecialchars(
                                        $user['user_type']
                                    ) ?>">

                                        <?= htmlspecialchars(
                                            formatUserRole(
                                                $user['user_type']
                                            )
                                        ) ?>

                                    </span>

                                </td>

                                <td>

                                    <form
                                        method="POST"
                                        action="admin_users.php"
                                        class="admin-user-role-form"
                                    >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= htmlspecialchars(
                                                $csrf_token
                                            ) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="update_role"
                                        >

                                        <input
                                            type="hidden"
                                            name="user_id"
                                            value="<?= (int)$user['id'] ?>"
                                        >

                                        <select
                                            name="new_role"
                                            aria-label="<?= htmlspecialchars(t('select_user_role')) ?>"
                                        >

                                            <option
                                                value="customer"
                                                <?= $user['user_type'] ===
                                                    'customer'
                                                    ? 'selected'
                                                    : '' ?>
                                            >
                                                <?= t('customer') ?>
                                            </option>

                                            <option
                                                value="owner"
                                                <?= $user['user_type'] ===
                                                    'owner'
                                                    ? 'selected'
                                                    : '' ?>
                                            >
                                                <?= t('cabin_owner') ?>
                                            </option>

                                            <option
                                                value="attraction_owner"
                                                <?= $user['user_type'] ===
                                                    'attraction_owner'
                                                    ? 'selected'
                                                    : '' ?>
                                            >
                                                <?= t('attraction_owner') ?>
                                            </option>

                                            <option
                                                value="admin"
                                                <?= $user['user_type'] ===
                                                    'admin'
                                                    ? 'selected'
                                                    : '' ?>
                                            >
                                                <?= t('administrator') ?>
                                            </option>

                                        </select>

                                        <button
                                            type="submit"
                                            onclick='return confirm(<?= json_encode(t('confirm_change_user_role')) ?>);'"
                                        >
                                            <?= t('update') ?>
                                        </button>

                                    </form>

                                </td>

                            </tr>

                        <?php endwhile; ?>

                    </tbody>

                </table>

            </div>

            <?php if ($total_pages > 1): ?>

                <nav class="admin-users-pagination">

                    <?php if ($current_page > 1): ?>

                        <a href="<?= htmlspecialchars(
                            buildUsersPageUrl(
                                $current_page - 1,
                                $search,
                                $selected_role
                            )
                        ) ?>">
                            <?= t('previous') ?>
                        </a>

                    <?php endif; ?>

                    <?php

                    $start_page = max(
                        1,
                        $current_page - 2
                    );

                    $end_page = min(
                        $total_pages,
                        $current_page + 2
                    );

                    ?>

                    <?php for (
                        $page = $start_page;
                        $page <= $end_page;
                        $page++
                    ): ?>

                        <a
                            href="<?= htmlspecialchars(
                                buildUsersPageUrl(
                                    $page,
                                    $search,
                                    $selected_role
                                )
                            ) ?>"
                            class="<?= $page === $current_page
                                ? 'active'
                                : '' ?>"
                        >
                            <?= $page ?>
                        </a>

                    <?php endfor; ?>

                    <?php if (
                        $current_page < $total_pages
                    ): ?>

                        <a href="<?= htmlspecialchars(
                            buildUsersPageUrl(
                                $current_page + 1,
                                $search,
                                $selected_role
                            )
                        ) ?>">
                            <?= t('next') ?>
                        </a>

                    <?php endif; ?>

                </nav>

            <?php endif; ?>

        <?php else: ?>

            <div class="admin-empty-state">

                <span>👤</span>

                <h3><?= t('no_users_found') ?></h3>

                <p>
                    <?= t('try_changing_search_or_role_filter') ?>
                </p>

            </div>

        <?php endif; ?>

    </section>

</main>

</body>
</html>

<?php

$users_stmt->close();
$conn->close();

?>