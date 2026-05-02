<?php
session_start();
include "connection.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (!isset($_GET['cabin_id'])) {
    die("Missing data");
}

$user_id = $_SESSION['user_id'];
$cabin_id = intval($_GET['cabin_id']);
$start_date = $_GET['start_date'];
$end_date = $_GET['end_date'];
$total_price = $_GET['total_price'] ?? 0;

/* ================= USER ================= */
$user = $conn->prepare("SELECT user_level FROM users WHERE id=?");
$user->bind_param("i", $user_id);
$user->execute();
$user = $user->get_result()->fetch_assoc();

$user_level = $user['user_level'];

/* ================= DISCOUNT ================= */
$discount = 0;

if ($user_level == 'vip') {
    $discount = 0.15;
} elseif ($user_level == 'regular') {
    $discount = 0.10;
}

/* ================= SERVICES ================= */
$services_string = $_GET['services'] ?? "";
$services_array = explode(",", $services_string);

$services_total = 0;

foreach ($services_array as $service_id) {

    if ($service_id == "") continue;

    $srv = $conn->prepare("SELECT price FROM services WHERE service_id=?");
    $srv->bind_param("i", $service_id);
    $srv->execute();
    $res = $srv->get_result()->fetch_assoc();

    if ($res) {
        $services_total += $res['price'];
    }
}

/* ================= BASE PRICE ================= */
$base_price = $total_price + $services_total;

/* ================= SEASON PRICING ================= */
$month = date('m', strtotime($start_date));

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
$rule->bind_param("is", $cabin_id, $season);
$rule->execute();
$res = $rule->get_result()->fetch_assoc();

$multiplier = $res['multiplier'] ?? 1;

$season_price = $base_price * $multiplier;

/* ================= DEMAND PRICING ================= */
$month_num = date('m', strtotime($start_date));

$demand = $conn->prepare("
    SELECT COUNT(*) as total
    FROM bookings
    WHERE cabin_id=? 
    AND MONTH(start_date)=?
    AND status='confirmed'
");
$demand->bind_param("ii", $cabin_id, $month_num);
$demand->execute();
$res = $demand->get_result()->fetch_assoc();

$count = $res['total'];

$demand_multiplier = 1;

if ($count > 10) {
    $demand_multiplier = 1.2;
} elseif ($count > 5) {
    $demand_multiplier = 1.1;
}

$demand_price = $season_price * $demand_multiplier;

/* ================= FINAL PRICE ================= */
$price_before_discount = $demand_price;
$final_price = $price_before_discount * (1 - $discount);

/* ================= PAYMENT ================= */
$message = "";

if (isset($_POST['pay'])) {

    /* ===== CHECK DUPLICATE BOOKING ===== */
    $check = $conn->prepare("
        SELECT * FROM bookings
        WHERE cabin_id=?
        AND NOT (end_date <= ? OR start_date >= ?)
        AND status='confirmed'
    ");
    $check->bind_param("iss", $cabin_id, $start_date, $end_date);
    $check->execute();

    if ($check->get_result()->num_rows > 0) {
        $message = "Selected dates are already booked!";
    } else {

        $insert = $conn->prepare("
            INSERT INTO bookings (cabin_id, user_id, start_date, end_date, total_price, status)
            VALUES (?, ?, ?, ?, ?, 'confirmed')
        ");
        $insert->bind_param("iissd", $cabin_id, $user_id, $start_date, $end_date, $final_price);

        if ($insert->execute()) {

            /* ===== LOYALTY UPDATE ===== */
            $conn->query("
                UPDATE users 
                SET total_bookings = total_bookings + 1,
                    loyalty_points = loyalty_points + 10
                WHERE id = $user_id
            ");

            header("Location: bookingconfirmation.php");
            exit();
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

<p><b>User Level:</b> <?= strtoupper($user_level) ?></p>

<p><b>Base Price:</b> $<?= number_format($base_price,2) ?></p>
<p><b>Season Multiplier:</b> x<?= $multiplier ?></p>
<p><b>Demand Multiplier:</b> x<?= $demand_multiplier ?></p>

<?php if($discount > 0) { ?>
<p><b>Discount:</b> <?= $discount*100 ?>%</p>
<?php } ?>

<h3>Total Price: $<?= number_format($final_price,2) ?></h3>

<?php if ($message) { ?>
<div class="message"><?= $message ?></div>
<?php } ?>

<form method="POST">

<input type="text" name="card" placeholder="Card Number" required>
<input type="text" name="expiry" placeholder="MM/YY" required>
<input type="text" name="cvv" placeholder="CVV" required>

<button name="pay">Pay Now</button>

</form>

</div>

</body>
</html>