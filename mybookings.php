<?php

session_start();

include "connection.php";
include "language.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if ($_SESSION['user_type'] !== 'customer') {
    die(t('customers_only'));
}

$user_id = (int) $_SESSION['user_id'];
$today = date("Y-m-d");

$stmt = $conn->prepare("
    SELECT 
        b.id AS booking_id,
        b.start_date,
        b.end_date,
        b.total_price,
        b.status,
        b.review_submitted,
        c.id AS cabin_id,
        c.name AS cabin_name,
        c.location,
        c.region,
        (
            SELECT image_path
            FROM cabin_images
            WHERE cabin_id = c.id
            LIMIT 1
        ) AS main_image
    FROM bookings b
    JOIN cabins c ON b.cabin_id = c.id
    WHERE b.user_id = ?
    ORDER BY b.start_date DESC
");

$stmt->bind_param("i", $user_id);
$stmt->execute();

$bookings = $stmt->get_result();

?>

<!DOCTYPE html>

<html
    lang="<?= current_language() ?>"
    dir="<?= is_rtl() ? 'rtl' : 'ltr' ?>"
>

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        <?= htmlspecialchars(t('my_bookings_page_title')) ?>
    </title>

    <link
        rel="stylesheet"
        href="style.css?v=<?= time() ?>"
    >

</head>

<body>

<?php include "navbar.php"; ?>

<div class="mybookings-page">

    <div class="mybookings-container">

        <div class="mybookings-hero">

            <span class="mini-title">
                <?= htmlspecialchars(t('customer_area')) ?>
            </span>

            <h1>
                <?= htmlspecialchars(t('my_bookings_title')) ?>
            </h1>

            <p>
                <?= htmlspecialchars(t('my_bookings_description')) ?>
            </p>

        </div>

        <?php if ($bookings->num_rows === 0): ?>

            <div class="empty-bookings">

                <h2>
                    <?= htmlspecialchars(t('no_bookings_yet')) ?>
                </h2>

                <p>
                    <?= htmlspecialchars(t('no_bookings_description')) ?>
                </p>

                <a href="search.php">
                    <?= htmlspecialchars(t('search_cabins')) ?>
                </a>

            </div>

        <?php endif; ?>

        <div class="booking-list">

            <?php while ($booking = $bookings->fetch_assoc()): ?>

                <?php

                $is_future = $booking['start_date'] >= $today;
                $modal_id = "modal_" . (int) $booking['booking_id'];

             $booking_region = strtolower(trim($booking['region']));

$att = $conn->prepare("
    SELECT *
    FROM attractions
    WHERE LOWER(TRIM(region)) = ?
    ORDER BY created_at DESC
");

$att->bind_param("s", $booking_region);
                $att->execute();

                $attractions = $att->get_result();

                ?>

                <div class="booking-card">

                    <img
                        src="<?= $booking['main_image']
                            ? htmlspecialchars($booking['main_image'])
                            : 'uploads/default.jpg'
                        ?>"
                        alt="<?= htmlspecialchars($booking['cabin_name']) ?>"
                    >

                    <div class="booking-content">

                        <h2>
                            <?= htmlspecialchars($booking['cabin_name']) ?>
                        </h2>

                        <p class="location">

                            📍 <?= htmlspecialchars($booking['location']) ?>

                            |

                            <?= strtoupper(
                                htmlspecialchars($booking['region'])
                            ) ?>

                        </p>

                        <div class="booking-info-grid">

                            <div>

                                <span>
                                    <?= htmlspecialchars(t('booking_from')) ?>
                                </span>

                                <b>
                                    <?= htmlspecialchars($booking['start_date']) ?>
                                </b>

                            </div>

                            <div>

                                <span>
                                    <?= htmlspecialchars(t('booking_to')) ?>
                                </span>

                                <b>
                                    <?= htmlspecialchars($booking['end_date']) ?>
                                </b>

                            </div>

                            <div>

                                <span>
                                    <?= htmlspecialchars(t('booking_status')) ?>
                                </span>

                                <b>
                                    <?= htmlspecialchars(
                                        t(
                                            'booking_status_' .
                                            strtolower($booking['status'])
                                        )
                                    ) ?>
                                </b>

                            </div>

                            <div>

                                <span>
                                    <?= htmlspecialchars(t('total_paid')) ?>
                                </span>

                                <b>
                                    ₪<?= number_format(
                                        (float) $booking['total_price'],
                                        2
                                    ) ?>
                                </b>

                            </div>

                        </div>

                        <?php if (
                            $is_future &&
                            $booking['status'] !== 'cancelled'
                        ): ?>

                            <p class="booking-note">
                                <?= htmlspecialchars(
                                    t('future_booking_note')
                                ) ?>
                            </p>

                            <button
                                type="button"
                                class="view-attractions-btn"
                                onclick='openModal(<?= json_encode($modal_id, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' 
                            >
                                <?= htmlspecialchars(
                                    t('view_nearby_attractions')
                                ) ?>
                            </button>

                        <?php else: ?>

                            <?php if (
                                $booking['status'] !== 'cancelled' &&
                                (int) $booking['review_submitted'] === 0
                            ): ?>

                                <p class="past-booking-note">
                                    <?= htmlspecialchars(
                                        t('completed_stay_review_note')
                                    ) ?>
                                </p>

                                <a
                                    class="view-attractions-btn"
                                    href="review.php?booking_id=<?= (int) $booking['booking_id'] ?>"
                                >
                                    ⭐ <?= htmlspecialchars(t('rate_your_stay')) ?>
                                </a>

                            <?php elseif (
                                (int) $booking['review_submitted'] === 1
                            ): ?>

                                <p class="past-booking-note">
                                    <?= htmlspecialchars(
                                        t('already_rated_message')
                                    ) ?>
                                </p>

                            <?php else: ?>

                                <p class="past-booking-note">
                                    <?= htmlspecialchars(
                                        t('attractions_future_only')
                                    ) ?>
                                </p>

                            <?php endif; ?>

                        <?php endif; ?>

                    </div>

                </div>

                <?php if (
                    $is_future &&
                    $booking['status'] !== 'cancelled'
                ): ?>

                    <div
                        id="<?= htmlspecialchars($modal_id) ?>"
                        class="attraction-modal"
                    >

                        <div class="attraction-modal-content">

                            <button
                                type="button"
                                class="close-modal"
                                aria-label="<?= htmlspecialchars(
                                    t('close')
                                ) ?>"
                                onclick='closeModal(<?= json_encode($modal_id, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' 
                            >
                                &times;
                            </button>

                            <h2>
                                <?= htmlspecialchars(
                                    t('attractions_near')
                                ) ?>

                                <?= htmlspecialchars(
                                    $booking['cabin_name']
                                ) ?>
                            </h2>

                            <p class="modal-subtitle">

                                <?= htmlspecialchars(
                                    t('attractions_region_match')
                                ) ?>

                                <b>
                                    <?= strtoupper(
                                        htmlspecialchars(
                                            $booking['region']
                                        )
                                    ) ?>
                                </b>

                            </p>

                            <?php if ($attractions->num_rows === 0): ?>

                                <div class="no-attractions">

                                    <h3>
                                        <?= htmlspecialchars(
                                            t('no_attractions_found')
                                        ) ?>
                                    </h3>

                                    <p>
                                        <?= htmlspecialchars(
                                            t('no_attractions_region')
                                        ) ?>
                                    </p>

                                </div>

                            <?php endif; ?>

                            <div class="modal-attractions-grid">

                                <?php while (
                                    $a = $attractions->fetch_assoc()
                                ): ?>

                                    <div class="modal-attraction-card">

                                        <span class="rule-badge">
                                            <?= htmlspecialchars(
                                                $a['region']
                                            ) ?>
                                        </span>

                                        <h3>
                                            <?= htmlspecialchars(
                                                $a['title']
                                            ) ?>
                                        </h3>

                                        <p class="location">
                                            📍 <?= htmlspecialchars(
                                                $a['location']
                                            ) ?>
                                        </p>

                                        <p>
                                            <?= htmlspecialchars(
                                                $a['description']
                                            ) ?>
                                        </p>

                                        <?php if (
                                            !empty($a['discount_text'])
                                        ): ?>

                                            <div class="discount-label">

                                                🎁 <?= htmlspecialchars(
                                                    $a['discount_text']
                                                ) ?>

                                            </div>

                                        <?php endif; ?>

                                    </div>

                                <?php endwhile; ?>

                            </div>

                        </div>

                    </div>

                <?php endif; ?>

                <?php $att->close(); ?>

            <?php endwhile; ?>

        </div>

    </div>

</div>

<script>

function openModal(id) {
    const modal = document.getElementById(id);

    if (!modal) {
        console.error("Attractions modal not found:", id);
        return;
    }

    modal.style.display = "flex";
    document.body.style.overflow = "hidden";
}

function closeModal(id) {
    const modal = document.getElementById(id);

    if (modal) {
        modal.style.display = "none";
        document.body.style.overflow = "";
    }
}

window.addEventListener("click", function (event) {

    const modals = document.getElementsByClassName(
        "attraction-modal"
    );

    for (let i = 0; i < modals.length; i++) {

        if (event.target === modals[i]) {
            modals[i].style.display = "none";
            document.body.style.overflow = "";
        }

    }

});

document.addEventListener("keydown", function (event) {

    if (event.key !== "Escape") {
        return;
    }

    const modals = document.getElementsByClassName(
        "attraction-modal"
    );

    for (let i = 0; i < modals.length; i++) {
        modals[i].style.display = "none";
    }

    document.body.style.overflow = "";

});

</script>

</body>
</html>

<?php

$stmt->close();
$conn->close();

?>