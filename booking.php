<?php
session_start();
include "connection.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (!isset($_GET['cabin_id'])) {
    die("Cabin not selected");
}

$user_id = $_SESSION['user_id'];
$cabin_id = intval($_GET['cabin_id']);
$message = "";
$total_price = null;

/* צימר */
$stmt = $conn->prepare("
    SELECT id, name, description, location, price_per_night, max_guests
    FROM cabins
    WHERE id=?
");
$stmt->bind_param("i", $cabin_id);
$stmt->execute();
$cabin = $stmt->get_result()->fetch_assoc();

if (!$cabin) {
    die("Cabin not found");
}

/* שירותים */
$services = $conn->prepare("
    SELECT s.service_id, s.service_name, s.description, s.price
    FROM services s
    JOIN cabin_services cs ON s.service_id = cs.service_id
    WHERE cs.cabin_id = ?
");
$services->bind_param("i", $cabin_id);
$services->execute();
$services_result = $services->get_result();

/* תמונות */
$images_stmt = $conn->prepare("
    SELECT image_path FROM cabin_images WHERE cabin_id=?
");
$images_stmt->bind_param("i", $cabin_id);
$images_stmt->execute();
$images_result = $images_stmt->get_result();

$all_images = [];
while ($img = $images_result->fetch_assoc()) {
    $all_images[] = $img['image_path'];
}

/* בדיקת זמינות + מחיר */
if (isset($_POST['check_booking'])) {

    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];

    if ($start_date >= $end_date) {
        $message = "Invalid dates.";
    } else {

        $check = $conn->prepare("
            SELECT id FROM bookings
            WHERE cabin_id=?
            AND status!='cancelled'
            AND NOT (end_date <= ? OR start_date >= ?)
        ");
        $check->bind_param("iss", $cabin_id, $start_date, $end_date);
        $check->execute();
        $check_result = $check->get_result();

        if ($check_result->num_rows > 0) {
            $message = "Already booked!";
        } else {

            $start = new DateTime($start_date);
            $end = new DateTime($end_date);
            $nights = $start->diff($end)->days;

            $base_price = $nights * $cabin['price_per_night'];

            $services_total = 0;

            if (isset($_POST['services'])) {
                foreach ($_POST['services'] as $service_id) {

                    $srv = $conn->prepare("SELECT price FROM services WHERE service_id=?");
                    $srv->bind_param("i", $service_id);
                    $srv->execute();
                    $srv_result = $srv->get_result()->fetch_assoc();

                    $services_total += $srv_result['price'];
                }
            }

            $total_price = $base_price + $services_total;
            $message = "Available!";
        }
    }
}

include "navbar.php";
?>

<!DOCTYPE html>
<html>
<head>
<title>Booking</title>
<link rel="stylesheet" href="style.css">
</head>

<body>

<div class="booking-page">

    <!-- גלריה -->
    <div class="booking-gallery">

        <?php if (count($all_images) > 0) { ?>
            <img id="mainImage" src="<?= $all_images[0] ?>" class="main-img">

            <div class="thumbs">
                <?php foreach($all_images as $img) { ?>
                    <img src="<?= $img ?>" class="thumb" onclick="changeImg(this.src)">
                <?php } ?>
            </div>
        <?php } else { ?>
            <img src="uploads/hero.jpg" class="main-img">
        <?php } ?>

    </div>

    <!-- פרטים -->
    <div class="booking-box">

        <h1><?= htmlspecialchars($cabin['name']) ?></h1>
        <p>📍 <?= htmlspecialchars($cabin['location']) ?></p>
        <p><b>$<?= $cabin['price_per_night'] ?></b> / night</p>

        <h3>About this cabin</h3>
        <p><?= nl2br(htmlspecialchars($cabin['description'])) ?></p>

        <h3>Select extra services</h3>

        <form method="POST">

            <div class="services-box">

            <?php while($s = $services_result->fetch_assoc()) { 

                $isPaid = $s['price'] > 0;
            ?>

                <label class="service-wrapper <?= !$isPaid ? 'disabled-service' : '' ?>">

                    <input 
                        type="checkbox" 
                        name="services[]" 
                        value="<?= $s['service_id'] ?>"
                        <?= !$isPaid ? 'disabled' : '' ?>
                    >

                    <div class="service-card">

                        <div class="service-icon">✨</div>

                        <div class="service-info">
                            <span class="service-name"><?= $s['service_name'] ?></span>
                            <span class="service-desc"><?= $s['description'] ?></span>
                        </div>

                        <div class="service-price">
                            <?php if ($isPaid) { ?>
                                +$<?= $s['price'] ?>
                            <?php } else { ?>
                                Included
                            <?php } ?>
                        </div>

                    </div>

                </label>

            <?php } ?>

            </div>

            <input type="date" name="start_date" required>
            <input type="date" name="end_date" required>

            <button type="submit" name="check_booking">Check Availability</button>

        </form>

        <?php if ($message != "") { ?>
            <div class="message"><?= $message ?></div>
        <?php } ?>

        <?php if ($total_price !== null) { ?>

            <h3>Total: $<?= $total_price ?></h3>

            <form method="GET" action="payment.php">
                <input type="hidden" name="cabin_id" value="<?= $cabin_id ?>">
                <input type="hidden" name="start_date" value="<?= $start_date ?>">
                <input type="hidden" name="end_date" value="<?= $end_date ?>">
                <input type="hidden" name="total_price" value="<?= $total_price ?>">
                <input type="hidden" name="services" value="<?= isset($_POST['services']) ? implode(',', $_POST['services']) : '' ?>">

                <button type="submit">Proceed to Payment</button>
            </form>

        <?php } ?>

    </div>

</div>

<script>
function changeImg(src){
    document.getElementById("mainImage").src = src;
}
</script>

</body>
</html>