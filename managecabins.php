<?php
session_start();
include "connection.php";
include "language.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$user_type = $_SESSION['user_type'] ?? '';

if ($user_type !== 'owner' && $user_type !== 'admin') {
    die("Access denied");
}

$is_admin = ($user_type === 'admin');

if ($is_admin) {
    $stmt = $conn->prepare("
        SELECT
            c.*,

            u.first_name AS owner_first_name,
            u.last_name AS owner_last_name,
            u.email AS owner_email,

            (
                SELECT ci.image_path
                FROM cabin_images ci
                WHERE ci.cabin_id = c.id
                LIMIT 1
            ) AS main_image,

            (
                SELECT COUNT(*)
                FROM bookings b
                WHERE b.cabin_id = c.id
                  AND b.status = 'confirmed'
                  AND YEAR(b.start_date) = YEAR(CURDATE())
            ) AS total_bookings,

            (
                SELECT IFNULL(SUM(b.owner_revenue), 0)
                FROM bookings b
                WHERE b.cabin_id = c.id
                  AND b.status = 'confirmed'
                  AND YEAR(b.start_date) = YEAR(CURDATE())
            ) AS revenue,

            (
                SELECT IFNULL(SUM(COALESCE(b.admin_commission, 0)), 0)
                FROM bookings b
                WHERE b.cabin_id = c.id
                  AND b.status = 'confirmed'
                  AND YEAR(b.start_date) = YEAR(CURDATE())
            ) AS admin_commission

        FROM cabins c

        LEFT JOIN users u
            ON c.owner_id = u.id

        ORDER BY c.id DESC
    ");
} else {
    $stmt = $conn->prepare("
        SELECT
            c.*,

            (
                SELECT ci.image_path
                FROM cabin_images ci
                WHERE ci.cabin_id = c.id
                LIMIT 1
            ) AS main_image,

            (
                SELECT COUNT(*)
                FROM bookings b
                WHERE b.cabin_id = c.id
                  AND b.status = 'confirmed'
                  AND YEAR(b.start_date) = YEAR(CURDATE())
            ) AS total_bookings,

            (
                SELECT IFNULL(SUM(b.owner_revenue), 0)
                FROM bookings b
                WHERE b.cabin_id = c.id
                  AND b.status = 'confirmed'
                  AND YEAR(b.start_date) = YEAR(CURDATE())
            ) AS revenue

        FROM cabins c

        WHERE c.owner_id = ?

        ORDER BY c.id DESC
    ");

    if ($stmt) {
        $stmt->bind_param("i", $user_id);
    }
}

if (!$stmt) {
    die("SQL ERROR: " . $conn->error);
}

$stmt->execute();
$result = $stmt->get_result();

$current_year = date('Y');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        <?= t('manage_cabins') ?>
    </title>

    <link
        rel="stylesheet"
        href="style.css?v=<?= time(); ?>"
    >
</head>

<body>

<?php include "navbar.php"; ?>

<div class="manage-page">
    <div class="manage-container">

        <div class="manage-hero">
            <div>
                <span class="mini-title">
                    <?= $is_admin ? t('admin_panel') : t('owner') ?>
                </span>

                <h1>
                    <?= t('manage_cabins') ?>
                </h1>

                <p>
                    <?php if ($is_admin): ?>
                        View all cabins, cabin owners, completed bookings
                        and business activity across ZimmerSmart.
                    <?php else: ?>
                        Control your cabins, prices, availability,
                        bookings and statistics from one place.
                    <?php endif; ?>
                </p>
            </div>
        </div>

     
 <?php if (!$is_admin): ?>

    <div class="owner-actions">

        <a href="add_cabins.php">
            ➕ <?= t('add_cabin') ?>
        </a>

        <a href="statistics.php">
            📊 <?= t('statistics_dashboard') ?>
        </a>

        <a href="pricingavailability.php">
            💰 <?= t('pricing_availability') ?>
        </a>

        <a href="bookingsmanagement.php">
            📅 <?= t('bookings') ?>
        </a>

    </div>

<?php endif; ?>

<div class="cabins-grid">

    <?php if ($result->num_rows === 0): ?>

  
                <div class="empty-owner-box">
                    <h2>
                        <?= $is_admin ? t('no_cabins_found') : t('no_cabins') ?>
                    </h2>

                    <p>
                        <?= $is_admin
                            ? t('no_cabins_found')
                            : t('add_cabin')
                        ?>
                    </p>

                    <?php if (!$is_admin): ?>
                        <a href="add_cabins.php">
                            <?= t('add_cabin') ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php while ($cabin = $result->fetch_assoc()): ?>
                <div class="owner-cabin-card">

                    <img
                        src="<?=
                            !empty($cabin['main_image'])
                                ? htmlspecialchars($cabin['main_image'])
                                : 'uploads/default.jpg'
                        ?>"
                        alt="<?= htmlspecialchars($cabin['name']) ?>"
                    >

                    <div class="owner-cabin-body">

                        <h2>
                            <?= htmlspecialchars($cabin['name']) ?>
                        </h2>

                        <p class="location">
                            📍 <?= htmlspecialchars($cabin['location']) ?>
                        </p>

                        <?php if ($is_admin): ?>
                            <div class="owner-note">
                                <strong>Cabin Owner:</strong>

                                <?= htmlspecialchars(
                                    trim(
                                        ($cabin['owner_first_name'] ?? '') .
                                        ' ' .
                                        ($cabin['owner_last_name'] ?? '')
                                    )
                                ) ?: 'Not available' ?>

                                <br>

                                <strong><?= t('email') ?>:</strong>

                                <?= htmlspecialchars(
                                    $cabin['owner_email'] ?? 'Not available'
                                ) ?>
                            </div>
                        <?php endif; ?>

                        <div class="cabin-info-row">
                            <div>
                                <span><?= t('price_per_night') ?></span>

                                <b>
                                    ₪<?= number_format(
                                        (float) $cabin['price_per_night'],
                                        2
                                    ) ?>
                                </b>
                            </div>

                            <div>
                                <span><?= t('max_guests') ?></span>

                                <b>
                                    <?= (int) $cabin['max_guests'] ?>
                                </b>
                            </div>
                        </div>

                        <div class="cabin-info-row">
                            <div>
                                <span>
                                    <?= t('total_bookings') ?> <?= $current_year ?>
                                </span>

                                <b>
                                    <?= (int) $cabin['total_bookings'] ?>
                                </b>
                            </div>

                            <div>
                                <span>
                                    <?= t('total_owner_revenue') ?> <?= $current_year ?>
                                </span>

                                <b>
                                    ₪<?= number_format(
                                        (float) $cabin['revenue'],
                                        2
                                    ) ?>
                                </b>
                            </div>
                        </div>

                        <?php if ($is_admin): ?>
                            <div class="cabin-info-row">
                                <div>
                                    <span>
                                        <?= t('commission') ?> <?= $current_year ?>
                                    </span>

                                    <b>
                                        ₪<?= number_format(
                                            (float) $cabin['admin_commission'],
                                            2
                                        ) ?>
                                    </b>
                                </div>

                                <div>
                                    <span>Cabin ID</span>

                                    <b>
                                        #<?= (int) $cabin['id'] ?>
                                    </b>
                                </div>
                            </div>
                        <?php else: ?>
                           <p class="owner-note">
    <?= t('smart_pricing_tip') ?>
</p>
                        <?php endif; ?>
<div class="cabin-buttons">

    <?php if (!$is_admin): ?>

        <a
            href="edit_cabin.php?id=<?= (int) $cabin['id'] ?>"
            class="btn-edit"
        >
            <?= t('edit_profile') ?>
        </a>

        <a
            href="pricingavailability.php?cabin_id=<?= (int) $cabin['id'] ?>"
            class="btn-price"
        >
            <?= t('pricing_availability') ?>
        </a>

        <a
            href="statistics.php?cabin_id=<?= (int) $cabin['id'] ?>"
            class="btn-stats"
        >
            <?= t('statistics_dashboard') ?>
        </a>

    <?php endif; ?>

</div>
                    </div>
                </div>
            <?php endwhile; ?>

        </div>
    </div>
</div>

</body>
</html>

<?php
$stmt->close();
$conn->close();
?>
