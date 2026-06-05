<?php
session_start();
include "connection.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (!isset($_GET['cabin_id'], $_GET['start_date'], $_GET['end_date'])) {
    die("Missing data");
}

$user_id = $_SESSION['user_id'];
$cabin_id = intval($_GET['cabin_id']);
$start_date = $_GET['start_date'];
$end_date = $_GET['end_date'];
$message = "";

/* CABIN */
$cabin_stmt = $conn->prepare("SELECT price_per_night FROM cabins WHERE id=?");
$cabin_stmt->bind_param("i", $cabin_id);
$cabin_stmt->execute();
$cabin = $cabin_stmt->get_result()->fetch_assoc();

if (!$cabin) {
    die("Cabin not found");
}

/* DATES */
if ($start_date >= $end_date) {
    die("Invalid dates");
}

$start = new DateTime($start_date);
$end = new DateTime($end_date);
$nights = $start->diff($end)->days;

/* USER LEVEL */
$user_stmt = $conn->prepare("SELECT user_level FROM users WHERE id=?");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user = $user_stmt->get_result()->fetch_assoc();

$user_level = $user['user_level'] ?? 'new';

$discount = 0;
if ($user_level == 'vip') {
    $discount = 0.15;
} elseif ($user_level == 'regular') {
    $discount = 0.10;
}

/* SERVICES */
$services_string = $_GET['services'] ?? "";
$services_array = [];

if ($services_string != "") {
    $services_array = explode(",", $services_string);
}

$services_total = 0;
$services_names = [];

foreach ($services_array as $service_id) {
    if ($service_id == "") {
        continue;
    }

    $service_id = intval($service_id);

    $srv = $conn->prepare("
        SELECT service_name, price 
        FROM services 
        WHERE service_id=?
    ");
    $srv->bind_param("i", $service_id);
    $srv->execute();
    $res = $srv->get_result()->fetch_assoc();

    if ($res) {
        $services_total += $res['price'];
        $services_names[] = $res['service_name'];
    }
}

/* BASE PRICE */
$cabin_price = $nights * $cabin['price_per_night'];
$base_price = $cabin_price + $services_total;

/* SEASON PRICING */
$month = intval(date('m', strtotime($start_date)));

if ($month >= 6 && $month <= 8) {
    $season = 'high';
} elseif ($month >= 3 && $month <= 5) {
    $season = 'medium';
} else {
    $season = 'low';
}

$rule = $conn->prepare("
    SELECT multiplier 
    FROM pricing_rules 
    WHERE cabin_id=? AND season=?
");

if (!$rule) {
    die("SQL Error in pricing_rules: " . $conn->error);
}

$rule->bind_param("is", $cabin_id, $season);
$rule->execute();
$res = $rule->get_result()->fetch_assoc();

$season_multiplier = $res['multiplier'] ?? 1;
$season_price = $base_price * $season_multiplier;

/* DEMAND PRICING */
$demand = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM bookings
    WHERE cabin_id=?
    AND MONTH(start_date)=?
    AND status='confirmed'
");

if (!$demand) {
    die("SQL Error in demand pricing: " . $conn->error);
}

$demand->bind_param("ii", $cabin_id, $month);
$demand->execute();
$res = $demand->get_result()->fetch_assoc();

$booking_count = $res['total'] ?? 0;

$demand_multiplier = 1;

if ($booking_count > 10) {
    $demand_multiplier = 1.20;
} elseif ($booking_count > 5) {
    $demand_multiplier = 1.10;
}

$price_before_discount = $season_price * $demand_multiplier;
$final_price = $price_before_discount * (1 - $discount);

/* PAYMENT */
if (isset($_POST['pay'])) {

    /* CHECK EXISTING BOOKINGS */
    $check_booking = $conn->prepare("
        SELECT id FROM bookings
        WHERE cabin_id=?
        AND status!='cancelled'
        AND NOT (end_date <= ? OR start_date >= ?)
    ");

    if (!$check_booking) {
        die("SQL Error in check bookings: " . $conn->error);
    }

    $check_booking->bind_param("iss", $cabin_id, $start_date, $end_date);
    $check_booking->execute();

    /* CHECK BLOCKED DATES */
    $check_blocked = $conn->prepare("
        SELECT id FROM cabin_unavailable_dates
        WHERE cabin_id=?
        AND NOT (end_date <= ? OR start_date >= ?)
    ");

    if (!$check_blocked) {
        die("SQL Error in blocked dates: " . $conn->error);
    }

    $check_blocked->bind_param("iss", $cabin_id, $start_date, $end_date);
    $check_blocked->execute();

    if ($check_booking->get_result()->num_rows > 0) {
        $message = "Selected dates are already booked.";
    } elseif ($check_blocked->get_result()->num_rows > 0) {
        $message = "This cabin is unavailable for the selected dates.";
    } else {

        $insert = $conn->prepare("
            INSERT INTO bookings 
            (cabin_id, user_id, start_date, end_date, total_price, status)
            VALUES (?, ?, ?, ?, ?, 'confirmed')
        ");

        if (!$insert) {
            die("SQL Error in insert booking: " . $conn->error);
        }

        $insert->bind_param("iissd", $cabin_id, $user_id, $start_date, $end_date, $final_price);

        if ($insert->execute()) {

            /* LOYALTY UPDATE */
            $update = $conn->prepare("
                UPDATE users
                SET total_bookings = total_bookings + 1,
                    loyalty_points = loyalty_points + 10
                WHERE id=?
            ");

            if (!$update) {
                die("SQL Error in loyalty update: " . $conn->error);
            }

            $update->bind_param("i", $user_id);
            $update->execute();

            $get = $conn->prepare("
                SELECT total_bookings 
                FROM users 
                WHERE id=?
            ");

            if (!$get) {
                die("SQL Error in get total bookings: " . $conn->error);
            }

            $get->bind_param("i", $user_id);
            $get->execute();
            $user_after = $get->get_result()->fetch_assoc();

            $total_bookings = $user_after['total_bookings'];

            if ($total_bookings >= 10) {
                $new_level = 'vip';
            } elseif ($total_bookings >= 3) {
                $new_level = 'regular';
            } else {
                $new_level = 'new';
            }

            $level_update = $conn->prepare("
                UPDATE users 
                SET user_level=?
                WHERE id=?
            ");

            if (!$level_update) {
                die("SQL Error in level update: " . $conn->error);
            }

            $level_update->bind_param("si", $new_level, $user_id);
            $level_update->execute();

            header("Location: bookingconfirmation.php");
            exit();

        } else {
            $message = "Error saving booking.";
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Payment</title>
    <link rel="stylesheet" href="style.css">
</head>

<body>

<div class="payment-box">

    <h2>Payment</h2>

    <p><b>From:</b> <?= htmlspecialchars($start_date) ?></p>
    <p><b>To:</b> <?= htmlspecialchars($end_date) ?></p>
    <p><b>Nights:</b> <?= $nights ?></p>

    <p><b>User Level:</b> <?= strtoupper(htmlspecialchars($user_level)) ?></p>

    <?php if (!empty($services_names)) { ?>
        <h3>Selected Services</h3>
        <?php foreach ($services_names as $name) { ?>
            <p><?= htmlspecialchars($name) ?></p>
        <?php } ?>
    <?php } ?>

    <p><b>Cabin Price:</b> $<?= number_format($cabin_price, 2) ?></p>
    <p><b>Services Price:</b> $<?= number_format($services_total, 2) ?></p>
    <p><b>Season:</b> <?= ucfirst($season) ?> / x<?= $season_multiplier ?></p>
    <p><b>Demand Multiplier:</b> x<?= $demand_multiplier ?></p>

    <?php if ($discount > 0) { ?>
        <p><b>Loyalty Discount:</b> <?= $discount * 100 ?>%</p>
    <?php } ?>

    <h3>Total Price: $<?= number_format($final_price, 2) ?></h3>

    <?php if ($message != "") { ?>
        <div class="message"><?= htmlspecialchars($message) ?></div>
    <?php } ?>

    <form method="POST">
        <input type="text" name="card" placeholder="Card Number" required>
        <input type="text" name="expiry" placeholder="MM/YY" required>
        <input type="text" name="cvv" placeholder="CVV" required>

        <div class="secure">Secure Payment</div>

        <button type="submit" name="pay">Pay Now</button>
    </form>

</div>

</body>
</html>