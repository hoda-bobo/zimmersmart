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
$discount_text = "";

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

/* תמונות */
$images_stmt = $conn->prepare("
    SELECT image_path
    FROM cabin_images
    WHERE cabin_id=?
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

    if ($start_date == "" || $end_date == "") {
        $message = "Please choose start and end date.";
    } elseif ($start_date >= $end_date) {
        $message = "End date must be after start date.";
    } else {

        /* בדיקה שאין חפיפה עם booking קיים */
        $check = $conn->prepare("
            SELECT id
            FROM bookings
            WHERE cabin_id = ?
            AND status != 'cancelled'
            AND NOT (end_date <= ? OR start_date >= ?)
        ");
        $check->bind_param("iss", $cabin_id, $start_date, $end_date);
        $check->execute();
        $check_result = $check->get_result();

        if ($check_result->num_rows > 0) {
            $message = "This cabin is already booked for the selected dates.";
        } else {

            /* availability */
            $availability = $conn->prepare("
                SELECT availability_id
                FROM availability
                WHERE cabin_id = ?
                AND available_date >= ?
                AND available_date < ?
                AND is_available = 0
            ");
            $availability->bind_param("iss", $cabin_id, $start_date, $end_date);
            $availability->execute();
            $availability_result = $availability->get_result();

            if ($availability_result->num_rows > 0) {
                $message = "Some selected dates are not available.";
            } else {

                $start = new DateTime($start_date);
                $end = new DateTime($end_date);
                $nights = $start->diff($end)->days;

                $base_price = $nights * $cabin['price_per_night'];
                $final_price = $base_price;

                /* discount */
                $discount = $conn->prepare("
                    SELECT title, discount_percent
                    FROM discounts
                    WHERE cabin_id = ?
                    AND start_date <= ?
                    AND end_date >= ?
                    LIMIT 1
                ");
                $discount->bind_param("iss", $cabin_id, $start_date, $end_date);
                $discount->execute();
                $discount_result = $discount->get_result();
                $discount_data = $discount_result->fetch_assoc();

                if ($discount_data) {
                    $discount_percent = $discount_data['discount_percent'];
                    $discount_amount = ($base_price * $discount_percent) / 100;
                    $final_price = $base_price - $discount_amount;
                    $discount_text = $discount_data['title'] . " - " . $discount_percent . "% off";
                    $message = "Dates are available. Discount applied.";
                } else {
                    $message = "Dates are available.";
                }

                $total_price = $final_price;
            }
        }
    }
}

/* אישור הזמנה */
if (isset($_POST['confirm_booking'])) {
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $total_price = $_POST['total_price'];

    if ($start_date >= $end_date) {
        $message = "Invalid dates.";
    } else {

        /* בדיקה חוזרת לפני הכנסה */
        $check = $conn->prepare("
            SELECT id
            FROM bookings
            WHERE cabin_id = ?
            AND status != 'cancelled'
            AND NOT (end_date <= ? OR start_date >= ?)
        ");
        $check->bind_param("iss", $cabin_id, $start_date, $end_date);
        $check->execute();
        $check_result = $check->get_result();

        if ($check_result->num_rows > 0) {
            $message = "Sorry, this cabin was just booked by someone else.";
            $total_price = null;
        } else {
            $insert = $conn->prepare("
                INSERT INTO bookings (cabin_id, user_id, start_date, end_date, total_price, status)
                VALUES (?, ?, ?, ?, ?, 'pending')
            ");
            $insert->bind_param("iissd", $cabin_id, $user_id, $start_date, $end_date, $total_price);

            if ($insert->execute()) {
                $message = "Booking created successfully! Waiting for approval.";
                $total_price = null;
            } else {
                $message = "Error creating booking.";
            }
        }
    }
}
?>

<?php include "navbar.php"; ?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Booking - <?= htmlspecialchars($cabin['name']) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="booking-page">

    <div class="booking-gallery">
        <?php if (count($all_images) > 0) { ?>
            <div class="main-image-box">
                <img id="mainCabinImage" src="<?= htmlspecialchars($all_images[0]) ?>" class="main-cabin-image" alt="Cabin Image">
            </div>

            <div class="thumbs-row">
                <?php foreach($all_images as $img_path) { ?>
                    <img src="<?= htmlspecialchars($img_path) ?>" class="thumb-image" onclick="changeMainImage(this.src)" alt="Cabin Thumbnail">
                <?php } ?>
            </div>
        <?php } else { ?>
            <div class="main-image-box">
                <img src="uploads/hero.jpg" class="main-cabin-image" alt="Default Image">
            </div>
        <?php } ?>
    </div>

    <div class="booking-details-box">
        <h1><?= htmlspecialchars($cabin['name']) ?></h1>
        <p class="booking-location">📍 <?= htmlspecialchars($cabin['location']) ?></p>

        <p class="booking-description">
            <?= htmlspecialchars($cabin['description']) ?>
        </p>

        <div class="booking-info-line">
            <span><b>Price:</b> $<?= htmlspecialchars($cabin['price_per_night']) ?> / night</span>
            <span><b>Guests:</b> <?= htmlspecialchars($cabin['max_guests']) ?></span>
        </div>

        <?php if ($message != "") { ?>
            <div class="message"><?= htmlspecialchars($message) ?></div>
        <?php } ?>

        <form method="POST" class="booking-form">
            <label>Start Date</label>
            <input type="date" name="start_date" required value="<?= $_POST['start_date'] ?? '' ?>">

            <label>End Date</label>
            <input type="date" name="end_date" required value="<?= $_POST['end_date'] ?? '' ?>">

            <button type="submit" name="check_booking" class="btn">Check Availability</button>
        </form>

        <?php if ($total_price !== null) { ?>
            <div class="booking-summary">
                <?php
                $start = new DateTime($_POST['start_date']);
                $end = new DateTime($_POST['end_date']);
                $nights = $start->diff($end)->days;
                ?>

                <p><b>Nights:</b> <?= $nights ?></p>

                <?php if ($discount_text != "") { ?>
                    <p class="discount-text"><b>Discount:</b> <?= htmlspecialchars($discount_text) ?></p>
                <?php } ?>

                <h3>Total Price: $<?= number_format($total_price, 2) ?></h3>

                <form method="POST">
                    <input type="hidden" name="start_date" value="<?= htmlspecialchars($_POST['start_date']) ?>">
                    <input type="hidden" name="end_date" value="<?= htmlspecialchars($_POST['end_date']) ?>">
                    <input type="hidden" name="total_price" value="<?= htmlspecialchars($total_price) ?>">

                    <button type="submit" name="confirm_booking" class="btn">Confirm Booking</button>
                </form>
            </div>
        <?php } ?>
    </div>

</div>

<script>
function changeMainImage(src) {
    document.getElementById("mainCabinImage").src = src;
}
</script>

</body>
</html>