<?php

session_start();

include "connection.php";
include "language.php";
include "lead_helper.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (!isset($_GET['cabin_id']) || !is_numeric($_GET['cabin_id'])) {
    die(t('cabin_not_selected') ?: 'Cabin not selected');
}

$user_id = (int) $_SESSION['user_id'];
$cabin_id = (int) $_GET['cabin_id'];

$message = "";
$message_type = "";
$total_price = null;

$start_date = "";
$end_date = "";
$selected_service_ids = [];

/* =========================================================
   CABIN
   ========================================================= */

$stmt = $conn->prepare("
    SELECT
        id,
        name,
        description,
        location,
        price_per_night,
        max_guests
    FROM cabins
    WHERE id = ?
    LIMIT 1
");

if (!$stmt) {
    die("CABIN SQL ERROR: " . $conn->error);
}

$stmt->bind_param("i", $cabin_id);
$stmt->execute();

$cabin = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$cabin) {
    die(t('cabin_not_found') ?: 'Cabin not found');
}

/* =========================================================
   SAVE LEAD
   ========================================================= */

if (function_exists('addLead')) {

    $lead_type = "viewed_no_booking";
    $notes = t('lead_viewed_no_booking_note');

    if ($notes === '' || $notes === null) {
        $notes = 'Customer viewed the cabin but did not complete a booking.';
    }

    $check_lead = $conn->prepare("
        SELECT id
        FROM leads
        WHERE user_id = ?
          AND cabin_id = ?
          AND lead_type = ?
        LIMIT 1
    ");

    if ($check_lead) {

        $check_lead->bind_param(
            "iis",
            $user_id,
            $cabin_id,
            $lead_type
        );

        $check_lead->execute();
        $lead_result = $check_lead->get_result();

        if ($lead_result->num_rows === 0) {
            addLead(
                $conn,
                $user_id,
                $cabin_id,
                $lead_type,
                $notes
            );
        }

        $check_lead->close();
    }
}

/* =========================================================
   SERVICES
   ========================================================= */

$services = $conn->prepare("
    SELECT
        s.service_id,
        s.service_name,
        s.description,
        s.price
    FROM services s
    JOIN cabin_services cs
        ON s.service_id = cs.service_id
    WHERE cs.cabin_id = ?
    ORDER BY s.service_name
");

if (!$services) {
    die("SERVICES SQL ERROR: " . $conn->error);
}

$services->bind_param("i", $cabin_id);
$services->execute();

$services_result = $services->get_result();

/* =========================================================
   IMAGES
   ========================================================= */

$images_stmt = $conn->prepare("
    SELECT image_path
    FROM cabin_images
    WHERE cabin_id = ?
");

if (!$images_stmt) {
    die("IMAGES SQL ERROR: " . $conn->error);
}

$images_stmt->bind_param("i", $cabin_id);
$images_stmt->execute();

$images_result = $images_stmt->get_result();

$all_images = [];

while ($image = $images_result->fetch_assoc()) {
    $all_images[] = $image['image_path'];
}

$images_stmt->close();

/* =========================================================
   CHECK AVAILABILITY + CALCULATE PRICE
   ========================================================= */

if (isset($_POST['check_booking'])) {

    $start_date = trim($_POST['start_date'] ?? '');
    $end_date = trim($_POST['end_date'] ?? '');

    $selected_service_ids = array_values(
        array_filter(
            array_map(
                'intval',
                $_POST['services'] ?? []
            ),
            static fn($id) => $id > 0
        )
    );

    if ($start_date === '' || $end_date === '') {

        $message = t('please_select_dates')
            ?: 'Please select check-in and check-out dates.';

        $message_type = "error";

    } elseif (
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)
    ) {

        $message = t('invalid_dates')
            ?: 'The selected dates are invalid.';

        $message_type = "error";

    } else {

        try {

            $start = DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $start_date
            );

            $end = DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $end_date
            );

            $today = new DateTimeImmutable('today');

            if (!$start || !$end) {

                $message = t('invalid_dates')
                    ?: 'The selected dates are invalid.';

                $message_type = "error";

            } elseif ($start < $today) {

                $message = t('start_date_in_past')
                    ?: 'The check-in date cannot be in the past.';

                $message_type = "error";

            } elseif ($start >= $end) {

                $message = t('invalid_dates')
                    ?: 'Check-out must be after check-in.';

                $message_type = "error";

            } else {

                $nights = (int) $start->diff($end)->days;

                if ($nights < 1 || $nights > 60) {

                    $message = t('invalid_dates')
                        ?: 'Please choose a stay between 1 and 60 nights.';

                    $message_type = "error";

                } else {

                    $check = $conn->prepare("
                        SELECT id
                        FROM bookings
                        WHERE cabin_id = ?
                          AND status != 'cancelled'
                          AND NOT (
                              end_date <= ?
                              OR start_date >= ?
                          )
                        LIMIT 1
                    ");

                    if (!$check) {
                        die("AVAILABILITY SQL ERROR: " . $conn->error);
                    }

                    $check->bind_param(
                        "iss",
                        $cabin_id,
                        $start_date,
                        $end_date
                    );

                    $check->execute();
                    $check_result = $check->get_result();

                    if ($check_result->num_rows > 0) {

                        $message = t('already_booked')
                            ?: 'The cabin is not available for the selected dates.';

                        $message_type = "error";

                    } else {

                        $night_price = (float) $cabin['price_per_night'];
                        $base_price = $nights * $night_price;
                        $services_total = 0.0;

                        if (!empty($selected_service_ids)) {

                            $placeholders = implode(
                                ',',
                                array_fill(
                                    0,
                                    count($selected_service_ids),
                                    '?'
                                )
                            );

                            $service_types = str_repeat(
                                'i',
                                count($selected_service_ids)
                            );

                            $price_stmt = $conn->prepare("
                                SELECT
                                    COALESCE(SUM(s.price), 0)
                                    AS services_total
                                FROM services s
                                JOIN cabin_services cs
                                    ON cs.service_id = s.service_id
                                WHERE cs.cabin_id = ?
                                  AND s.service_id IN ($placeholders)
                            ");

                            if (!$price_stmt) {
                                die("SERVICE PRICE SQL ERROR: " . $conn->error);
                            }

                            $price_params = array_merge(
                                [$cabin_id],
                                $selected_service_ids
                            );

                            $price_stmt->bind_param(
                                "i" . $service_types,
                                ...$price_params
                            );

                            $price_stmt->execute();

                            $price_row = $price_stmt
                                ->get_result()
                                ->fetch_assoc();

                            $services_total = (float) (
                                $price_row['services_total'] ?? 0
                            );

                            $price_stmt->close();
                        }

                        $total_price = round(
                            $base_price + $services_total,
                            2
                        );

                        $message = t('available')
                            ?: 'The cabin is available for the selected dates!';

                        $message_type = "success";
                    }

                    $check->close();
                }
            }

        } catch (Throwable $e) {

            $message = t('invalid_dates')
                ?: 'The selected dates are invalid.';

            $message_type = "error";
        }
    }
}

$today_value = date('Y-m-d');

$language_code = current_language();
$is_rtl_page = is_rtl();

$check_availability_text = t('check_availability');

if (
    $check_availability_text === '' ||
    $check_availability_text === null
) {
    $check_availability_text =
        $is_rtl_page
            ? 'בדיקת זמינות'
            : 'Check availability';
}

$payment_text = t('proceed_to_payment');

if ($payment_text === '' || $payment_text === null) {
    $payment_text =
        $is_rtl_page
            ? 'המשך לתשלום'
            : 'Proceed to payment';
}

$total_text = t('total');

if ($total_text === '' || $total_text === null) {
    $total_text =
        $is_rtl_page
            ? 'סה״כ'
            : 'Total';
}

?>

<!DOCTYPE html>
<html
    lang="<?= htmlspecialchars($language_code) ?>"
    dir="<?= $is_rtl_page ? 'rtl' : 'ltr' ?>"
>
<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        <?= htmlspecialchars(t('booking') ?: 'Booking') ?>
    </title>

    <link
        rel="stylesheet"
        href="style.css?v=<?= time() ?>"
    >

</head>

<body class="<?= $is_rtl_page ? 'rtl-site' : 'ltr-site' ?>">

<?php include "navbar.php"; ?>

<main class="booking-page">

    <section class="booking-gallery">

        <?php if (!empty($all_images)): ?>

            <img
                id="mainImage"
                src="<?= htmlspecialchars(
                    $all_images[0],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
                class="main-img"
                alt="<?= htmlspecialchars(
                    $cabin['name'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
            >

            <?php if (count($all_images) > 1): ?>

                <div class="thumbs">

                    <?php foreach ($all_images as $image): ?>

                        <button
                            type="button"
                            class="thumb-button"
                            onclick="changeImg(
                                '<?= htmlspecialchars(
                                    $image,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>'
                            )"
                        >
                            <img
                                src="<?= htmlspecialchars(
                                    $image,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                class="thumb"
                                alt="<?= htmlspecialchars(
                                    $cabin['name'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                            >
                        </button>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        <?php else: ?>

            <img
                src="uploads/hero.jpg"
                class="main-img"
                alt="<?= htmlspecialchars(
                    t('default_cabin_image')
                    ?: 'Default cabin image'
                ) ?>"
            >

        <?php endif; ?>

    </section>

    <section class="booking-box">

        <h1>
            <?= htmlspecialchars(
                $cabin['name'],
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </h1>

        <p class="booking-location">
            📍
            <?= htmlspecialchars(
                $cabin['location'],
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </p>

        <p class="booking-night-price">

            <strong>
                ₪<?= number_format(
                    (float) $cabin['price_per_night'],
                    2
                ) ?>
            </strong>

            <span>
                /
                <?= htmlspecialchars(t('night') ?: 'night') ?>
            </span>

        </p>

        <h3>
            <?= htmlspecialchars(
                t('about_this_cabin')
                ?: 'About this cabin'
            ) ?>
        </h3>

        <p class="booking-description">
            <?= nl2br(
                htmlspecialchars(
                    $cabin['description'],
                    ENT_QUOTES,
                    'UTF-8'
                )
            ) ?>
        </p>

        <h3>
            <?= htmlspecialchars(
                t('select_extra_services')
                ?: 'Select extra services'
            ) ?>
        </h3>

        <form
            method="POST"
            class="booking-form"
            novalidate
        >

            <div class="services-box">

                <?php while ($service = $services_result->fetch_assoc()): ?>

                    <?php
                    $service_id = (int) $service['service_id'];
                    $is_paid = (float) $service['price'] > 0;
                    $is_selected = in_array(
                        $service_id,
                        $selected_service_ids,
                        true
                    );
                    ?>

                    <label
                        class="service-wrapper
                        <?= !$is_paid ? 'disabled-service' : '' ?>"
                    >

                        <input
                            type="checkbox"
                            name="services[]"
                            value="<?= $service_id ?>"
                            <?= !$is_paid ? 'disabled' : '' ?>
                            <?= $is_selected ? 'checked' : '' ?>
                        >

                        <span class="service-card">

                            <span class="service-icon">
                                ✨
                            </span>

                            <span class="service-info">

                                <span class="service-name">
                                    <?= htmlspecialchars(
                                        $service['service_name'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </span>

                                <span class="service-desc">
                                    <?= htmlspecialchars(
                                        $service['description'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </span>

                            </span>

                            <span class="service-price">

                                <?php if ($is_paid): ?>

                                    +₪<?= number_format(
                                        (float) $service['price'],
                                        2
                                    ) ?>

                                <?php else: ?>

                                    <?= htmlspecialchars(
                                        t('included')
                                        ?: 'Included'
                                    ) ?>

                                <?php endif; ?>

                            </span>

                        </span>

                    </label>

                <?php endwhile; ?>

            </div>

            <div class="booking-date-grid">

                <div class="booking-field">

                    <label for="start_date">
                        <?= htmlspecialchars(
                            t('check_in_date')
                            ?: 'Check-in date'
                        ) ?>
                    </label>

                    <input
                        type="date"
                        id="start_date"
                        name="start_date"
                        min="<?= htmlspecialchars($today_value) ?>"
                        required
                        value="<?= htmlspecialchars(
                            $start_date,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                    >

                </div>

                <div class="booking-field">

                    <label for="end_date">
                        <?= htmlspecialchars(
                            t('check_out_date')
                            ?: 'Check-out date'
                        ) ?>
                    </label>

                    <input
                        type="date"
                        id="end_date"
                        name="end_date"
                        min="<?= htmlspecialchars($today_value) ?>"
                        required
                        value="<?= htmlspecialchars(
                            $end_date,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                    >

                </div>

            </div>

            <button
                type="submit"
                name="check_booking"
                value="1"
                class="btn booking-check-button"
            >
                <?= htmlspecialchars($check_availability_text) ?>
            </button>

        </form>

        <?php if ($message !== ""): ?>

            <div
                class="message <?= htmlspecialchars(
                    $message_type,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
                role="status"
            >
                <?= htmlspecialchars(
                    $message,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </div>

        <?php endif; ?>

        <?php if ($total_price !== null): ?>

            <div class="booking-total-box">

                <span class="booking-total-label">
                    <?= htmlspecialchars($total_text) ?>
                </span>

                <strong class="booking-total-price">
                    ₪<?= number_format(
                        (float) $total_price,
                        2
                    ) ?>
                </strong>

            </div>

            <form
                method="GET"
                action="payment.php"
                class="payment-forward-form"
            >

                <input
                    type="hidden"
                    name="cabin_id"
                    value="<?= $cabin_id ?>"
                >

                <input
                    type="hidden"
                    name="start_date"
                    value="<?= htmlspecialchars(
                        $start_date,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                >

                <input
                    type="hidden"
                    name="end_date"
                    value="<?= htmlspecialchars(
                        $end_date,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                >

                <input
                    type="hidden"
                    name="total_price"
                    value="<?= htmlspecialchars(
                        (string) $total_price,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                >

                <input
                    type="hidden"
                    name="services"
                    value="<?= htmlspecialchars(
                        implode(',', $selected_service_ids),
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                >

                <button
                    type="submit"
                    class="btn booking-payment-button"
                >
                    <?= htmlspecialchars($payment_text) ?>
                </button>

            </form>

        <?php endif; ?>

    </section>

</main>

<script>

function changeImg(src) {

    const mainImage = document.getElementById('mainImage');

    if (mainImage) {
        mainImage.src = src;
    }
}

const startDateInput =
    document.getElementById('start_date');

const endDateInput =
    document.getElementById('end_date');

if (startDateInput && endDateInput) {

    startDateInput.addEventListener(
        'change',
        function () {

            endDateInput.min = this.value;

            if (
                endDateInput.value &&
                endDateInput.value <= this.value
            ) {
                endDateInput.value = '';
            }
        }
    );

    if (startDateInput.value) {
        endDateInput.min = startDateInput.value;
    }
}

</script>

<?php include "footer.php"; ?>

</body>
</html>

<?php
$services->close();
$conn->close();
?>
