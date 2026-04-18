<?php
session_start();
include "connection.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

/* USER */
$stmt = $conn->prepare("
    SELECT first_name, last_name, email, phone, user_type
    FROM users
    WHERE id=?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

/* BOOKINGS */
$bookings = $conn->prepare("
    SELECT b.*, c.name AS cabin_name
    FROM bookings b
    JOIN cabins c ON b.cabin_id = c.id
    WHERE b.user_id=?
    ORDER BY b.start_date DESC
");
$bookings->bind_param("i", $user_id);
$bookings->execute();
$bookings_result = $bookings->get_result();

/* FAVORITES */
$fav = $conn->prepare("
    SELECT c.name, c.location
    FROM favorites f
    JOIN cabins c ON f.cabin_id = c.id
    WHERE f.user_id=?
");
$fav->bind_param("i", $user_id);
$fav->execute();
$fav_result = $fav->get_result();
?>

<?php include "navbar.php"; ?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>My Profile</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="profile-page-fixed">

    <div class="profile-hero-fixed">
        <div class="profile-hero-overlay-fixed">
            <p class="profile-small-title-fixed">My Account</p>
            <h1>Welcome, <?= htmlspecialchars($user['first_name'] . " " . $user['last_name']) ?> ✨</h1>
            <p class="profile-subtitle-fixed">
                Manage your personal details, bookings and favorite cabins
            </p>
        </div>
    </div>

    <div class="profile-content-fixed">

        <div class="profile-main-fixed">
            <div class="profile-user-box-fixed">
                <div class="profile-avatar-fixed">
                    <?= strtoupper(substr($user['first_name'], 0, 1)) ?>
                </div>

                <div class="profile-user-info-fixed">
                    <h2><?= htmlspecialchars($user['first_name'] . " " . $user['last_name']) ?></h2>
                    <p><b>Email:</b> <?= htmlspecialchars($user['email']) ?></p>
                    <p><b>Phone:</b> <?= htmlspecialchars($user['phone']) ?></p>
                    <span class="profile-role-fixed"><?= htmlspecialchars($user['user_type']) ?></span>
                </div>
            </div>

            <div class="profile-actions-fixed">
                <a href="edit_profile.php" class="profile-btn-fixed">Edit Profile</a>
                <a href="change_password.php" class="profile-btn-fixed profile-btn-light-fixed">Change Password</a>
            </div>
        </div>

        <div class="profile-stats-fixed">
            <div class="profile-stat-card-fixed">
                <h3><?= $bookings_result->num_rows ?></h3>
                <p>Bookings</p>
            </div>

            <div class="profile-stat-card-fixed">
                <h3><?= $fav_result->num_rows ?></h3>
                <p>Favorites</p>
            </div>

            <div class="profile-stat-card-fixed">
                <h3><?= htmlspecialchars($user['user_type']) ?></h3>
                <p>Role</p>
            </div>
        </div>

        <div class="profile-sections-fixed">

            <div class="profile-box-fixed">
                <h3>My Bookings</h3>

                <?php
                $bookings->execute();
                $bookings_result = $bookings->get_result();
                ?>

                <?php if ($bookings_result->num_rows > 0) { ?>
                    <?php while($b = $bookings_result->fetch_assoc()) { ?>
                        <div class="profile-item-fixed">
                            <div>
                                <h4><?= htmlspecialchars($b['cabin_name']) ?></h4>
                                <p><?= htmlspecialchars($b['start_date']) ?> → <?= htmlspecialchars($b['end_date']) ?></p>
                            </div>

                            <div class="profile-item-right-fixed">
                                <span class="profile-status-fixed <?= htmlspecialchars($b['status']) ?>">
                                    <?= htmlspecialchars($b['status']) ?>
                                </span>
                                <p class="profile-price-fixed">$<?= htmlspecialchars($b['total_price']) ?></p>
                            </div>
                        </div>
                    <?php } ?>
                <?php } else { ?>
                    <p class="profile-empty-fixed">No bookings yet</p>
                <?php } ?>
            </div>

            <div class="profile-box-fixed">
                <h3>My Favorites</h3>

                <?php
                $fav->execute();
                $fav_result = $fav->get_result();
                ?>

                <?php if ($fav_result->num_rows > 0) { ?>
                    <?php while($f = $fav_result->fetch_assoc()) { ?>
                        <div class="profile-item-fixed">
                            <div>
                                <h4><?= htmlspecialchars($f['name']) ?></h4>
                                <p><?= htmlspecialchars($f['location']) ?></p>
                            </div>

                            <div class="profile-item-right-fixed">
                                <span class="profile-favorite-fixed">❤ Favorite</span>
                            </div>
                        </div>
                    <?php } ?>
                <?php } else { ?>
                    <p class="profile-empty-fixed">No favorites yet</p>
                <?php } ?>
            </div>

        </div>

    </div>

</div>

</body>
</html>