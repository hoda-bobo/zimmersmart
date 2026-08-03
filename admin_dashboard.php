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

$user_id = (int)$_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Check that the current user is an admin
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
    die(t('sql_error') . $conn->error);
}

$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();

$user_result = $user_stmt->get_result();
$current_user = $user_result->fetch_assoc();

$user_stmt->close();

if (!$current_user) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$_SESSION['user_type'] = $current_user['user_type'];

if ($current_user['user_type'] !== 'admin') {
    header("Location: dashboard.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| Helper function for count queries
|--------------------------------------------------------------------------
*/

function getCount(mysqli $conn, string $query): int
{
    $result = $conn->query($query);

    if (!$result) {
        return 0;
    }

    $row = $result->fetch_assoc();

    return (int)($row['total'] ?? 0);
}

/*
|--------------------------------------------------------------------------
| Dashboard statistics
|--------------------------------------------------------------------------
*/

$total_users = getCount(
    $conn,
    "SELECT COUNT(*) AS total FROM users"
);

$total_customers = getCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM users
     WHERE user_type = 'customer'"
);

$total_owners = getCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM users
     WHERE user_type = 'owner'"
);

$total_attraction_owners = getCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM users
     WHERE user_type = 'attraction_owner'"
);

$pending_requests = getCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM role_requests
     WHERE request_status = 'pending'"
);

$approved_requests = getCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM role_requests
     WHERE request_status = 'approved'"
);

$rejected_requests = getCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM role_requests
     WHERE request_status = 'rejected'"
);

$total_cabins = getCount(
    $conn,
    "SELECT COUNT(*) AS total FROM cabins"
);

$total_bookings = getCount(
    $conn,
    "SELECT COUNT(*) AS total FROM bookings"
);

$confirmed_bookings = getCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM bookings
     WHERE status = 'confirmed'"
);

$pending_bookings = getCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM bookings
     WHERE status = 'pending'"
);

$total_attractions = getCount(
    $conn,
    "SELECT COUNT(*) AS total FROM attractions"
);



/*
|--------------------------------------------------------------------------
| Admin revenue statistics - current year only
|--------------------------------------------------------------------------
|
| Admin revenue comes from three sources:
| 1. 10% commission from completed bookings.
| 2. Paid and approved cabin-owner registrations.
| 3. Paid and approved attraction-owner registrations.
|
*/

$current_year = (int)date('Y');

/* Booking commissions for the current year */
$booking_revenue_result = $conn->query("\n    SELECT\n        COALESCE(SUM(admin_commission), 0) AS total,\n        COUNT(*) AS completed_bookings\n    FROM bookings\n    WHERE status = 'confirmed'\n      AND end_date <= CURDATE()\n      AND YEAR(end_date) = YEAR(CURDATE())\n");

$booking_revenue_row = $booking_revenue_result
    ? $booking_revenue_result->fetch_assoc()
    : [];

$booking_commission_revenue = (float)($booking_revenue_row['total'] ?? 0);
$completed_revenue_bookings = (int)($booking_revenue_row['completed_bookings'] ?? 0);

/* Cabin-owner registration revenue for the current year */
$cabin_registration_result = $conn->query("\n    SELECT\n        COALESCE(SUM(payment_amount), 0) AS total,\n        COUNT(*) AS registrations\n    FROM role_requests\n    WHERE requested_role = 'owner'\n      AND request_status = 'approved'\n      AND LOWER(payment_status) = 'paid'\n      AND YEAR(created_at) = YEAR(CURDATE())\n");

$cabin_registration_row = $cabin_registration_result
    ? $cabin_registration_result->fetch_assoc()
    : [];

$cabin_owner_registration_revenue = (float)($cabin_registration_row['total'] ?? 0);
$cabin_owner_registrations = (int)($cabin_registration_row['registrations'] ?? 0);

/* Attraction-owner registration revenue for the current year */
$attraction_registration_result = $conn->query("\n    SELECT\n        COALESCE(SUM(payment_amount), 0) AS total,\n        COUNT(*) AS registrations\n    FROM role_requests\n    WHERE requested_role = 'attraction_owner'\n      AND request_status = 'approved'\n      AND LOWER(payment_status) = 'paid'\n      AND YEAR(created_at) = YEAR(CURDATE())\n");

$attraction_registration_row = $attraction_registration_result
    ? $attraction_registration_result->fetch_assoc()
    : [];

$attraction_owner_registration_revenue = (float)($attraction_registration_row['total'] ?? 0);
$attraction_owner_registrations = (int)($attraction_registration_row['registrations'] ?? 0);

/* Total admin revenue for the current year */
$total_admin_revenue =
    $booking_commission_revenue +
    $cabin_owner_registration_revenue +
    $attraction_owner_registration_revenue;

/* Total admin revenue for the current month */
$booking_month_result = $conn->query("\n    SELECT COALESCE(SUM(admin_commission), 0) AS total\n    FROM bookings\n    WHERE status = 'confirmed'\n      AND end_date <= CURDATE()\n      AND YEAR(end_date) = YEAR(CURDATE())\n      AND MONTH(end_date) = MONTH(CURDATE())\n");

$booking_revenue_month = $booking_month_result
    ? (float)($booking_month_result->fetch_assoc()['total'] ?? 0)
    : 0;

$cabin_month_result = $conn->query("\n    SELECT COALESCE(SUM(payment_amount), 0) AS total\n    FROM role_requests\n    WHERE requested_role = 'owner'\n      AND request_status = 'approved'\n      AND LOWER(payment_status) = 'paid'\n      AND YEAR(created_at) = YEAR(CURDATE())\n      AND MONTH(created_at) = MONTH(CURDATE())\n");

$cabin_registration_revenue_month = $cabin_month_result
    ? (float)($cabin_month_result->fetch_assoc()['total'] ?? 0)
    : 0;

$attraction_month_result = $conn->query("\n    SELECT COALESCE(SUM(payment_amount), 0) AS total\n    FROM role_requests\n    WHERE requested_role = 'attraction_owner'\n      AND request_status = 'approved'\n      AND LOWER(payment_status) = 'paid'\n      AND YEAR(created_at) = YEAR(CURDATE())\n      AND MONTH(created_at) = MONTH(CURDATE())\n");

$attraction_registration_revenue_month = $attraction_month_result
    ? (float)($attraction_month_result->fetch_assoc()['total'] ?? 0)
    : 0;

$total_admin_revenue_month =
    $booking_revenue_month +
    $cabin_registration_revenue_month +
    $attraction_registration_revenue_month;

/* Revenue-source chart */
$revenue_source_labels = [
    t('booking_commissions'),
    t('cabin_owner_registrations'),
    t('attraction_owner_registrations')
];

$revenue_source_values = [
    $booking_commission_revenue,
    $cabin_owner_registration_revenue,
    $attraction_owner_registration_revenue
];

/* Monthly revenue by source - January to December */
$month_labels = [
    t('month_jan'), t('month_feb'), t('month_mar'),
    t('month_apr'), t('month_may'), t('month_jun'),
    t('month_jul'), t('month_aug'), t('month_sep'),
    t('month_oct'), t('month_nov'), t('month_dec')
];

$monthly_booking_commissions = array_fill(0, 12, 0.0);
$monthly_cabin_registrations = array_fill(0, 12, 0.0);
$monthly_attraction_registrations = array_fill(0, 12, 0.0);

$monthly_booking_result = $conn->query("\n    SELECT\n        MONTH(end_date) AS month_number,\n        COALESCE(SUM(admin_commission), 0) AS total\n    FROM bookings\n    WHERE status = 'confirmed'\n      AND end_date <= CURDATE()\n      AND YEAR(end_date) = YEAR(CURDATE())\n    GROUP BY MONTH(end_date)\n");

if ($monthly_booking_result) {
    while ($row = $monthly_booking_result->fetch_assoc()) {
        $month_index = (int)$row['month_number'] - 1;

        if ($month_index >= 0 && $month_index < 12) {
            $monthly_booking_commissions[$month_index] = (float)$row['total'];
        }
    }
}

$monthly_registration_result = $conn->query("\n    SELECT\n        MONTH(created_at) AS month_number,\n        requested_role,\n        COALESCE(SUM(payment_amount), 0) AS total\n    FROM role_requests\n    WHERE request_status = 'approved'\n      AND LOWER(payment_status) = 'paid'\n      AND requested_role IN ('owner', 'attraction_owner')\n      AND YEAR(created_at) = YEAR(CURDATE())\n    GROUP BY MONTH(created_at), requested_role\n");

if ($monthly_registration_result) {
    while ($row = $monthly_registration_result->fetch_assoc()) {
        $month_index = (int)$row['month_number'] - 1;

        if ($month_index < 0 || $month_index >= 12) {
            continue;
        }

        if ($row['requested_role'] === 'owner') {
            $monthly_cabin_registrations[$month_index] = (float)$row['total'];
        } elseif ($row['requested_role'] === 'attraction_owner') {
            $monthly_attraction_registrations[$month_index] = (float)$row['total'];
        }
    }
}

/* Top cabin owners by the commission they generated for the admin */
$top_owners = $conn->query("\n    SELECT\n        u.id,\n        u.first_name,\n        u.last_name,\n        COUNT(DISTINCT c.id) AS cabins_count,\n        COUNT(b.id) AS bookings_count,\n        COALESCE(SUM(b.admin_commission), 0) AS admin_commission\n    FROM users u\n    LEFT JOIN cabins c\n        ON c.owner_id = u.id\n    LEFT JOIN bookings b\n        ON b.cabin_id = c.id\n       AND b.status = 'confirmed'\n       AND b.end_date <= CURDATE()\n       AND YEAR(b.end_date) = YEAR(CURDATE())\n    WHERE u.user_type = 'owner'\n    GROUP BY u.id, u.first_name, u.last_name\n    ORDER BY admin_commission DESC, bookings_count DESC\n    LIMIT 5\n");

/* Top cabins by the commission they generated for the admin */
$top_cabins = $conn->query("\n    SELECT\n        c.id,\n        c.name,\n        u.first_name,\n        u.last_name,\n        COUNT(b.id) AS bookings_count,\n        COALESCE(SUM(b.admin_commission), 0) AS admin_commission\n    FROM cabins c\n    INNER JOIN users u\n        ON u.id = c.owner_id\n    LEFT JOIN bookings b\n        ON b.cabin_id = c.id\n       AND b.status = 'confirmed'\n       AND b.end_date <= CURDATE()\n       AND YEAR(b.end_date) = YEAR(CURDATE())\n    GROUP BY c.id, c.name, u.first_name, u.last_name\n    ORDER BY admin_commission DESC, bookings_count DESC\n    LIMIT 5\n");




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

    <title><?= t('admin_dashboard_title') ?> | ZimmerSmart</title>

    <link
        rel="stylesheet"
        href="style.css?v=<?= time() ?>"
    >

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

</head>

<body>

<?php include "navbar.php"; ?>

<main class="admin-dashboard-page">

    <section class="admin-dashboard-header">

        <div>

            <span class="admin-dashboard-label">
                <?= t('zimmersmart_administration') ?>
            </span>

            <h1>
                <?= t('welcome') ?>,
                <?= htmlspecialchars($current_user['first_name']) ?>
            </h1>

            <p>
                <?= t('admin_dashboard_description') ?>
            </p>

        </div>

        <div class="admin-profile-summary">

            <div class="admin-profile-avatar">

                <?= htmlspecialchars(
                    strtoupper(
                        mb_substr($current_user['first_name'], 0, 1) .
                        mb_substr($current_user['last_name'], 0, 1)
                    )
                ) ?>

            </div>

            <div>

                <strong>
                    <?= htmlspecialchars(
                        $current_user['first_name'] . ' ' .
                        $current_user['last_name']
                    ) ?>
                </strong>

                <span>
                    <?= htmlspecialchars($current_user['email']) ?>
                </span>

                <small><?= t('administrator') ?></small>

            </div>

        </div>

    </section>


    <section class="admin-management-section">

        <div class="admin-section-heading">

            <span><?= t('admin_dashboard_title') ?></span>

            <h2><?= t('choose_section_to_manage') ?></h2>

            <p><?= t('admin_dashboard_description') ?></p>

        </div>

        <div class="admin-management-grid">

            <a
                href="admin_users.php"
                class="admin-management-card"
            >

                <div class="admin-management-icon">01</div>

                <div>

                    <h3><?= t('manage_users') ?></h3>

                    <p>
                        <?= t('manage_users_description') ?>
                    </p>

                </div>
            </a>

            <a
                href="admin_role_requests.php"
                class="admin-management-card"
            >

                <div class="admin-management-icon">02</div>

                <div>

                    <h3><?= t('partner_requests') ?></h3>

                    <p>
                        <?= t('approve_reject_partner_requests') ?>
                    </p>

                    <?php if ($pending_requests > 0): ?>

                        <span class="admin-card-badge">
                            <?= number_format($pending_requests) ?> <?= t('pending') ?>
                        </span>

                    <?php endif; ?>

                </div>
            </a>

            <a
                href="managecabins.php"
                class="admin-management-card"
            >

                <div class="admin-management-icon">03</div>

                <div>

                    <h3><?= t('manage_cabins') ?></h3>

                    <p>
                        <?= t('manage_cabins_description') ?>
                    </p>

                </div>
            </a>





            <a
                href="#admin-statistics"
                class="admin-management-card"
            >

                <div class="admin-management-icon">04</div>

                <div>

                    <h3><?= t('statistics_dashboard') ?></h3>

                    <p>
                        <?= t('admin_dashboard_description') ?>
                    </p>

                </div>
            </a>


        </div>

    </section>


    <section id="admin-statistics" class="admin-section-heading admin-statistics-heading">
        <span><?= t('statistics_dashboard') ?></span>
        <h2><?= t('statistics') ?></h2>
        <p><?= t('income_current_year_by_source') ?></p>
    </section>

    <section class="admin-stats-grid">

        <article class="admin-stat-card">

            <div class="admin-stat-icon">
                US
            </div>

            <div>

                <span><?= t('total_users') ?></span>

                <strong>
                    <?= number_format($total_users) ?>
                </strong>

                <small>
                    <?= number_format($total_customers) ?> <?= t('customers') ?>
                </small>

            </div>

        </article>

        <article class="admin-stat-card">

            <div class="admin-stat-icon">
                RQ
            </div>

            <div>

                <span><?= t('pending_requests') ?></span>

                <strong>
                    <?= number_format($pending_requests) ?>
                </strong>

                <small>
                    <?= t('waiting_for_admin_review') ?>
                </small>

            </div>

        </article>

        <article class="admin-stat-card">

            <div class="admin-stat-icon">
                OW
            </div>

            <div>

                <span><?= t('cabin_owners') ?></span>

                <strong>
                    <?= number_format($total_owners) ?>
                </strong>

                <small>
                    <?= number_format($total_cabins) ?> <?= t('cabins') ?>
                </small>

            </div>

        </article>

        <article class="admin-stat-card">

            <div class="admin-stat-icon">
                AT
            </div>

            <div>

                <span><?= t('attraction_owners') ?></span>

                <strong>
                    <?= number_format($total_attraction_owners) ?>
                </strong>

                <small>
                    <?= number_format($total_attractions) ?> <?= t('attractions') ?>
                </small>

            </div>

        </article>

        <article class="admin-stat-card">

            <div class="admin-stat-icon">
                BK
            </div>

            <div>

                <span><?= t('total_bookings') ?></span>

                <strong>
                    <?= number_format($total_bookings) ?>
                </strong>

                <small>
                    <?= number_format($confirmed_bookings) ?> <?= t('confirmed') ?>
                </small>

            </div>

        </article>

        <article class="admin-stat-card">

            <div class="admin-stat-icon">
                AP
            </div>

            <div>

                <span><?= t('approved_requests') ?></span>

                <strong>
                    <?= number_format($approved_requests) ?>
                </strong>

                <small>
                    <?= number_format($rejected_requests) ?> <?= t('rejected') ?>
                </small>

            </div>

        </article>

    </section>

    <section class="admin-financial-section">

        <div class="admin-financial-heading">
            <span><?= t('admin_income') ?></span>
            <h2><?= t('my_revenue_overview') ?> — <?= $current_year ?></h2>
            <p>
                <?= t('income_current_year_by_source') ?>
            </p>
        </div>

        <div class="admin-financial-grid">

            <article class="admin-financial-card">
                <span><?= t('total_admin_revenue') ?></span>
                <strong>₪<?= number_format($total_admin_revenue, 2) ?></strong>
                <small><?= sprintf(t('all_revenue_sources_in_year'), $current_year) ?></small>
            </article>

            <article class="admin-financial-card">
                <span><?= t('revenue_this_month') ?></span>
                <strong>₪<?= number_format($total_admin_revenue_month, 2) ?></strong>
                <small><?= t('revenue_all_sources_this_month') ?></small>
            </article>

            <article class="admin-financial-card">
                <span><?= t('booking_commissions') ?></span>
                <strong>₪<?= number_format($booking_commission_revenue, 2) ?></strong>
                <small><?= sprintf(t('commission_from_completed_bookings'), number_format($completed_revenue_bookings)) ?></small>
            </article>

            <article class="admin-financial-card">
                <span><?= t('cabin_owner_registrations') ?></span>
                <strong>₪<?= number_format($cabin_owner_registration_revenue, 2) ?></strong>
                <small><?= sprintf(t('paid_approved_registrations'), number_format($cabin_owner_registrations)) ?></small>
            </article>

            <article class="admin-financial-card">
                <span><?= t('attraction_owner_registrations') ?></span>
                <strong>₪<?= number_format($attraction_owner_registration_revenue, 2) ?></strong>
                <small><?= sprintf(t('paid_approved_registrations'), number_format($attraction_owner_registrations)) ?></small>
            </article>

        </div>

        <div class="admin-financial-layout">

            <div class="admin-financial-panel">
                <h3><?= t('monthly_revenue_by_source') ?> — <?= $current_year ?></h3>
                <p class="admin-chart-description">
                    <?= t('source_contribution_each_month') ?>
                </p>
                <canvas id="adminMonthlyRevenueChart"></canvas>
            </div>

            <div class="admin-financial-panel">
                <h3><?= t('where_revenue_comes_from') ?></h3>
                <p class="admin-chart-description">
                    <?= sprintf(t('income_distribution_in_year'), $current_year) ?>
                </p>
                <canvas id="adminRevenueSourcesChart"></canvas>
            </div>

        </div>

        <div class="admin-financial-tables">

            <div class="admin-financial-panel">
                <h3><?= t('top_cabin_owners') ?> — <?= $current_year ?></h3>

                <div class="admin-table-wrap">
                    <table class="admin-financial-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th><?= t('owner') ?></th>
                                <th><?= t('cabins') ?></th>
                                <th><?= t('completed_bookings') ?></th>
                                <th><?= t('your_commission') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($top_owners && $top_owners->num_rows > 0): ?>
                            <?php $owner_rank = 1; ?>
                            <?php while ($owner = $top_owners->fetch_assoc()): ?>
                                <tr>
                                    <td><span class="admin-rank"><?= $owner_rank++ ?></span></td>
                                    <td><strong><?= htmlspecialchars($owner['first_name'] . ' ' . $owner['last_name']) ?></strong></td>
                                    <td><?= number_format((int)$owner['cabins_count']) ?></td>
                                    <td><?= number_format((int)$owner['bookings_count']) ?></td>
                                    <td>₪<?= number_format((float)$owner['admin_commission'], 2) ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5"><?= t('no_completed_booking_stats_year') ?></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="admin-financial-panel">
                <h3><?= t('top_cabins') ?> — <?= $current_year ?></h3>

                <div class="admin-table-wrap">
                    <table class="admin-financial-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th><?= t('cabin') ?></th>
                                <th><?= t('owner') ?></th>
                                <th><?= t('completed_bookings') ?></th>
                                <th><?= t('your_commission') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($top_cabins && $top_cabins->num_rows > 0): ?>
                            <?php $cabin_rank = 1; ?>
                            <?php while ($cabin = $top_cabins->fetch_assoc()): ?>
                                <tr>
                                    <td><span class="admin-rank"><?= $cabin_rank++ ?></span></td>
                                    <td><strong><?= htmlspecialchars($cabin['name']) ?></strong></td>
                                    <td><?= htmlspecialchars($cabin['first_name'] . ' ' . $cabin['last_name']) ?></td>
                                    <td><?= number_format((int)$cabin['bookings_count']) ?></td>
                                    <td>₪<?= number_format((float)$cabin['admin_commission'], 2) ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5"><?= t('no_completed_cabin_stats_year') ?></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

    </section>



<script>
const monthLabels = <?= json_encode($month_labels) ?>;
const monthlyBookingCommissions = <?= json_encode($monthly_booking_commissions) ?>;
const monthlyCabinRegistrations = <?= json_encode($monthly_cabin_registrations) ?>;
const monthlyAttractionRegistrations = <?= json_encode($monthly_attraction_registrations) ?>;

const revenueSourceLabels = <?= json_encode($revenue_source_labels) ?>;
const revenueSourceValues = <?= json_encode($revenue_source_values) ?>;

const monthlyCanvas = document.getElementById('adminMonthlyRevenueChart');

if (monthlyCanvas) {
    new Chart(monthlyCanvas, {
        type: 'bar',
        data: {
            labels: monthLabels,
            datasets: [
                {
                    label: <?= json_encode(t('booking_commissions') . ' (₪)') ?>,
                    data: monthlyBookingCommissions
                },
                {
                    label: <?= json_encode(t('cabin_owner_registrations') . ' (₪)') ?>,
                    data: monthlyCabinRegistrations
                },
                {
                    label: <?= json_encode(t('attraction_owner_registrations') . ' (₪)') ?>,
                    data: monthlyAttractionRegistrations
                }
            ]
        },
        options: {
            responsive: true,
            interaction: {
                mode: 'index',
                intersect: false
            },
            scales: {
                x: {
                    stacked: true
                },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: <?= json_encode(t('revenue') . ' (₪)') ?>
                    }
                }
            }
        }
    });
}

const sourceCanvas = document.getElementById('adminRevenueSourcesChart');

if (sourceCanvas) {
    new Chart(sourceCanvas, {
        type: 'doughnut',
        data: {
            labels: revenueSourceLabels,
            datasets: [
                {
                    data: revenueSourceValues
                }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'bottom'
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const value = Number(context.raw || 0);
                            const total = revenueSourceValues.reduce(
                                (sum, item) => sum + Number(item || 0),
                                0
                            );
                            const percentage = total > 0
                                ? ((value / total) * 100).toFixed(1)
                                : '0.0';

                            return `${context.label}: ₪${value.toLocaleString('he-IL', {
                                minimumFractionDigits: 2,
                                maximumFractionDigits: 2
                            })} (${percentage}%)`;
                        }
                    }
                }
            }
        }
    });
}
</script>


</body>
</html>

<?php
$conn->close();
?>