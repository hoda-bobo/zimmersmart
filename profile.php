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

<div class="profile-container">

    <!-- HEADER -->
    <div class="profile-header">
        <div class="avatar">
            <?= strtoupper(substr($user['first_name'],0,1)) ?>
        </div>

        <div>
            <h2><?= htmlspecialchars($user['first_name']." ".$user['last_name']) ?></h2>
            <p><?= htmlspecialchars($user['email']) ?></p>
        </div>

        <div class="profile-actions">
            <a href="edit_profile.php">Edit</a>
            <a href="change_password.php">Password</a>
        </div>
    </div>

    <!-- STATS -->
    <div class="profile-stats">
        <div><?= $bookings_result->num_rows ?> bookings</div>
        <div><?= $fav_result->num_rows ?> favorites</div>
        <div><?= htmlspecialchars($user['user_type']) ?></div>
    </div>

    <!-- BOOKINGS -->
    <div class="profile-section">
        <h3>My Bookings</h3>

        <?php if ($bookings_result->num_rows > 0) { ?>
            <?php while($b = $bookings_result->fetch_assoc()) { ?>

            <div class="profile-row">

                <div>
                    <b><?= htmlspecialchars($b['cabin_name']) ?></b>
                    <p><?= $b['start_date'] ?> → <?= $b['end_date'] ?></p>
                </div>

                <div class="right">
                    <span class="status"><?= $b['status'] ?></span>
                    <div class="price">₪<?= $b['total_price'] ?></div>
                </div>

            </div>

            <?php } ?>
        <?php } else { ?>
            <p>No bookings yet</p>
        <?php } ?>
    </div>

    <!-- FAVORITES -->
    <div class="profile-section">
        <h3>My Favorites</h3>

        <?php if ($fav_result->num_rows > 0) { ?>
            <?php while($f = $fav_result->fetch_assoc()) { ?>

            <div class="profile-row">

                <div>
                    <b><?= htmlspecialchars($f['name']) ?></b>
                    <p><?= htmlspecialchars($f['location']) ?></p>
                </div>

                <div class="right">
                    ❤
                </div>

            </div>

            <?php } ?>
        <?php } else { ?>
            <p>No favorites yet</p>
        <?php } ?>
    </div>

</div>
<?php include "footer.php"; ?>
</body>
</html>