<?php
session_start();
include "connection.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

/* בדיקות */
if (!isset($_GET['cabin_id'])) {
    die("Missing data");
}

$user_id = $_SESSION['user_id'];
$cabin_id = intval($_GET['cabin_id']);
$start_date = $_GET['start_date'] ?? "";
$end_date = $_GET['end_date'] ?? "";
$total_price = $_GET['total_price'] ?? 0;

/* שירותים שנבחרו */
$services_string = $_GET['services'] ?? "";
$services_array = [];

if ($services_string != "") {
    $services_array = explode(",", $services_string);
}

$services_total = 0;
$services_names = [];

/* שליפת שירותים */
if (!empty($services_array)) {

    foreach ($services_array as $service_id) {

        $srv = $conn->prepare("
            SELECT service_name, price 
            FROM services 
            WHERE service_id=?
        ");
        $srv->bind_param("i", $service_id);
        $srv->execute();
        $result = $srv->get_result()->fetch_assoc();

        if ($result) {
            $services_total += $result['price'];
            $services_names[] = $result['service_name'];
        }
    }
}

/* חישוב מחיר כולל */
$final_price = $total_price + $services_total;

/* הודעה */
$message = "";

/* תשלום */
if (isset($_POST['pay'])) {

    $insert = $conn->prepare("
        INSERT INTO bookings (cabin_id, user_id, start_date, end_date, total_price, status)
        VALUES (?, ?, ?, ?, ?, 'confirmed')
    ");

    $insert->bind_param("iissd", $cabin_id, $user_id, $start_date, $end_date, $final_price);

    if ($insert->execute()) {
        header("Location: bookingconfirmation.php");
        exit();
    } else {
        $message = "Error saving booking";
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

    <!-- שירותים -->
    <?php if (!empty($services_names)) { ?>
        <h3>Selected Services</h3>
        <?php foreach ($services_names as $name) { ?>
            <p>✔ <?= htmlspecialchars($name) ?></p>
        <?php } ?>
    <?php } ?>

    <!-- מחיר -->
    <h3>Total Price: $<?= htmlspecialchars($final_price) ?></h3>

    <?php if ($message != "") { ?>
        <div class="message"><?= $message ?></div>
    <?php } ?>

    <form method="POST">

        <label>Card Number</label>
        <input type="text" name="card" placeholder="1234 5678 9012 3456" required>

        <label>Expiry</label>
        <input type="text" name="expiry" placeholder="MM/YY" required>

        <label>CVV</label>
        <input type="text" name="cvv" placeholder="123" required>

        <div class="secure">
            🔒 Secure Payment
        </div>

        <button type="submit" name="pay">Pay Now</button>

    </form>

</div>

<?php include "footer.php"; ?>

</body>
</html>