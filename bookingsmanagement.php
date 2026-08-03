<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include "connection.php";
include "language.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];

/* Read the current user directly from the database */
$user_stmt = $conn->prepare("
    SELECT user_type
    FROM users
    WHERE id = ?
    LIMIT 1
");

if (!$user_stmt) {
    die("SQL ERROR: " . $conn->error);
}

$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();

$current_user = $user_stmt->get_result()->fetch_assoc();
$user_stmt->close();

if (!$current_user) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$_SESSION['user_type'] = $current_user['user_type'];

/* Only cabin owners may enter */
if ($current_user['user_type'] !== 'owner') {
    header("Location: dashboard.php");
    exit();
}

$owner_id = $user_id;
$message = "";
$message_type = "";

/* CSRF token */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];

/* Show the success message after redirect */
if (isset($_GET['updated']) && $_GET['updated'] === '1') {
    $message = t('status_updated');
    $message_type = "success";
}

/* Update one booking status */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['update_booking_status'])
) {
    $submitted_token = $_POST['csrf_token'] ?? "";
    $booking_id = (int) ($_POST['booking_id'] ?? 0);
    $new_status = strtolower(trim($_POST['status'] ?? ""));

    $allowed_statuses = [
        'pending',
        'confirmed',
        'completed',
        'cancelled'
    ];

    if (!hash_equals($_SESSION['csrf_token'], $submitted_token)) {
        $message = t('invalid_security_token');
        $message_type = "error";
    } elseif ($booking_id <= 0) {
        $message = t('booking_not_found');
        $message_type = "error";
    } elseif (!in_array($new_status, $allowed_statuses, true)) {
        $message = t('operation_failed');
        $message_type = "error";
    } else {
        /* Update only a booking that belongs to this owner's cabin */
        $update_stmt = $conn->prepare("
            UPDATE bookings b
            INNER JOIN cabins c
                ON b.cabin_id = c.id
            SET b.status = ?
            WHERE b.id = ?
              AND c.owner_id = ?
        ");

        if (!$update_stmt) {
            die("SQL ERROR: " . $conn->error);
        }

        $update_stmt->bind_param(
            "sii",
            $new_status,
            $booking_id,
            $owner_id
        );

        $update_stmt->execute();

        /* A matching row may already contain the selected status */
        $booking_exists_stmt = $conn->prepare("
            SELECT b.id
            FROM bookings b
            INNER JOIN cabins c
                ON b.cabin_id = c.id
            WHERE b.id = ?
              AND c.owner_id = ?
            LIMIT 1
        ");

        if (!$booking_exists_stmt) {
            $update_stmt->close();
            die("SQL ERROR: " . $conn->error);
        }

        $booking_exists_stmt->bind_param("ii", $booking_id, $owner_id);
        $booking_exists_stmt->execute();
        $booking_exists = $booking_exists_stmt->get_result()->fetch_assoc();
        $booking_exists_stmt->close();
        $update_stmt->close();

        if (!$booking_exists) {
            $message = t('booking_not_found');
            $message_type = "error";
        } else {
            header("Location: bookingsmanagement.php?updated=1");
            exit();
        }
    }
}

/*
 * Automatically mark past bookings as completed.
 * Old records may contain 0, an empty value or confirmed.
 */
$complete_stmt = $conn->prepare("
    UPDATE bookings b
    INNER JOIN cabins c
        ON b.cabin_id = c.id
    SET b.status = 'completed'
    WHERE c.owner_id = ?
      AND b.end_date < CURDATE()
      AND (
          b.status = '0'
          OR b.status = ''
          OR b.status IS NULL
          OR b.status = 'confirmed'
      )
");

if (!$complete_stmt) {
    die("SQL ERROR: " . $conn->error);
}

$complete_stmt->bind_param("i", $owner_id);
$complete_stmt->execute();
$complete_stmt->close();

/* Booking counts for this owner */
$stats_stmt = $conn->prepare("
    SELECT
        COUNT(b.id) AS total_bookings,

        COALESCE(
            SUM(
                CASE
                    WHEN b.status = 'pending' THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS pending_bookings,

        COALESCE(
            SUM(
                CASE
                    WHEN b.status = 'confirmed' THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS confirmed_bookings,

        COALESCE(
            SUM(
                CASE
                    WHEN b.status = 'completed' THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS completed_bookings,

        COALESCE(
            SUM(
                CASE
                    WHEN b.status = 'cancelled' THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS cancelled_bookings

    FROM bookings b

    INNER JOIN cabins c
        ON b.cabin_id = c.id

    WHERE c.owner_id = ?
");

if (!$stats_stmt) {
    die("SQL ERROR: " . $conn->error);
}

$stats_stmt->bind_param("i", $owner_id);
$stats_stmt->execute();

$booking_stats = $stats_stmt->get_result()->fetch_assoc();
$stats_stmt->close();

/* Get only bookings for this owner's cabins */
$bookings_stmt = $conn->prepare("
    SELECT
        b.id,
        b.start_date,
        b.end_date,
        b.total_price,
        b.status,
        c.name AS cabin_name,
        u.first_name,
        u.last_name,
        u.email,
        u.phone

    FROM bookings b

    INNER JOIN cabins c
        ON b.cabin_id = c.id

    INNER JOIN users u
        ON b.user_id = u.id

    WHERE c.owner_id = ?

    ORDER BY
        CASE
            WHEN b.status = 'pending' THEN 1
            WHEN b.status = 'confirmed' THEN 2
            WHEN b.status = 'completed' THEN 3
            WHEN b.status = 'cancelled' THEN 4
            ELSE 5
        END,
        b.start_date ASC
");

if (!$bookings_stmt) {
    die("SQL ERROR: " . $conn->error);
}

$bookings_stmt->bind_param("i", $owner_id);
$bookings_stmt->execute();

$bookings_result = $bookings_stmt->get_result();

function bookingStatusLabel(string $status): string
{
    $translation_key = 'status_' . strtolower($status);
    $translated = t($translation_key);

    return $translated !== $translation_key
        ? $translated
        : ucfirst($status);
}

?>

<!DOCTYPE html>

<html
    lang="<?= htmlspecialchars(current_language()) ?>"
    dir="<?= is_rtl() ? 'rtl' : 'ltr' ?>"
>

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        <?= htmlspecialchars(t('bookings_management')) ?>
    </title>

    <link
        rel="stylesheet"
        href="style.css?v=<?= time() ?>"
    >

</head>

<body>

<?php include "navbar.php"; ?>

<main class="owner-bookings-page">

    <section class="owner-bookings-container">

        <header class="owner-bookings-hero">

            <span>
                <?= htmlspecialchars(t('owner')) ?>
            </span>

            <h1>
                <?= htmlspecialchars(t('manage_bookings')) ?>
            </h1>

            <p>
                <?= htmlspecialchars(
                    t('owner_manage_bookings_description')
                ) ?>
            </p>

        </header>

        <?php if ($message !== ""): ?>

            <div class="owner-bookings-message <?= htmlspecialchars($message_type) ?>">
                <?= htmlspecialchars($message) ?>
            </div>

        <?php endif; ?>

        <section class="owner-bookings-stats">

            <article>
                <span><?= htmlspecialchars(t('all_bookings')) ?></span>

                <strong>
                    <?= (int) ($booking_stats['total_bookings'] ?? 0) ?>
                </strong>
            </article>

            <article>
                <span><?= htmlspecialchars(t('status_pending')) ?></span>

                <strong>
                    <?= (int) ($booking_stats['pending_bookings'] ?? 0) ?>
                </strong>
            </article>

            <article>
                <span><?= htmlspecialchars(t('status_confirmed')) ?></span>

                <strong>
                    <?= (int) ($booking_stats['confirmed_bookings'] ?? 0) ?>
                </strong>
            </article>

            <article>
                <span><?= htmlspecialchars(t('status_completed')) ?></span>

                <strong>
                    <?= (int) ($booking_stats['completed_bookings'] ?? 0) ?>
                </strong>
            </article>

            <article>
                <span><?= htmlspecialchars(t('status_cancelled')) ?></span>

                <strong>
                    <?= (int) ($booking_stats['cancelled_bookings'] ?? 0) ?>
                </strong>
            </article>

        </section>

        <?php if ($bookings_result->num_rows === 0): ?>

            <section class="owner-bookings-empty">

                <div>📅</div>

                <h2>
                    <?= htmlspecialchars(t('no_bookings_found')) ?>
                </h2>

                <p>
                    <?= htmlspecialchars(
                        t('owner_no_bookings_description')
                    ) ?>
                </p>

                <a href="managecabins.php">
                    <?= htmlspecialchars(t('manage_cabins')) ?>
                </a>

            </section>

        <?php else: ?>

            <section class="owner-bookings-list">

                <?php while ($booking = $bookings_result->fetch_assoc()): ?>

                    <article class="owner-booking-card">

                        <div class="owner-booking-card-header">

                            <div>

                                <span class="owner-booking-number">
                                    #<?= (int) $booking['id'] ?>
                                </span>

                                <h2>
                                    <?= htmlspecialchars(
                                        $booking['cabin_name']
                                    ) ?>
                                </h2>

                            </div>

                            <span class="owner-booking-status status-<?= htmlspecialchars(
                                strtolower($booking['status'])
                            ) ?>">
                                <?= htmlspecialchars(
                                    bookingStatusLabel(
                                        $booking['status']
                                    )
                                ) ?>
                            </span>

                        </div>

                        <div class="owner-booking-information">

                            <div>
                                <span>
                                    <?= htmlspecialchars(t('guest')) ?>
                                </span>

                                <strong>
                                    <?= htmlspecialchars(
                                        trim(
                                            $booking['first_name'] .
                                            ' ' .
                                            $booking['last_name']
                                        )
                                    ) ?>
                                </strong>
                            </div>

                            <div>
                                <span>
                                    <?= htmlspecialchars(t('email')) ?>
                                </span>

                                <strong>
                                    <a href="mailto:<?= htmlspecialchars(
                                        $booking['email']
                                    ) ?>">
                                        <?= htmlspecialchars(
                                            $booking['email']
                                        ) ?>
                                    </a>
                                </strong>
                            </div>

                            <div>
                                <span>
                                    <?= htmlspecialchars(t('phone')) ?>
                                </span>

                                <strong>
                                    <?php if (!empty($booking['phone'])): ?>

                                        <a href="tel:<?= htmlspecialchars(
                                            $booking['phone']
                                        ) ?>">
                                            <?= htmlspecialchars(
                                                $booking['phone']
                                            ) ?>
                                        </a>

                                    <?php else: ?>

                                        <?= htmlspecialchars(
                                            t('profile_not_provided')
                                        ) ?>

                                    <?php endif; ?>
                                </strong>
                            </div>

                            <div>
                                <span>
                                    <?= htmlspecialchars(t('check_in')) ?>
                                </span>

                                <strong>
                                    <?= htmlspecialchars(
                                        date(
                                            'd/m/Y',
                                            strtotime(
                                                $booking['start_date']
                                            )
                                        )
                                    ) ?>
                                </strong>
                            </div>

                            <div>
                                <span>
                                    <?= htmlspecialchars(t('check_out')) ?>
                                </span>

                                <strong>
                                    <?= htmlspecialchars(
                                        date(
                                            'd/m/Y',
                                            strtotime(
                                                $booking['end_date']
                                            )
                                        )
                                    ) ?>
                                </strong>
                            </div>

                            <div>
                                <span>
                                    <?= htmlspecialchars(t('total_price')) ?>
                                </span>

                                <strong class="owner-booking-price">
                                    ₪<?= number_format(
                                        (float) $booking['total_price'],
                                        2
                                    ) ?>
                                </strong>
                            </div>

                        </div>

                        <form
                            method="POST"
                            class="owner-booking-status-form"
                        >

                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= htmlspecialchars($csrf_token) ?>"
                            >

                            <input
                                type="hidden"
                                name="booking_id"
                                value="<?= (int) $booking['id'] ?>"
                            >

                            <label for="status_<?= (int) $booking['id'] ?>">
                                <?= htmlspecialchars(t('change_status')) ?>
                            </label>

                            <select
                                id="status_<?= (int) $booking['id'] ?>"
                                name="status"
                            >

                                <?php
                                $statuses = [
                                    'pending',
                                    'confirmed',
                                    'completed',
                                    'cancelled'
                                ];
                                ?>

                                <?php foreach ($statuses as $status): ?>

                                    <option
                                        value="<?= htmlspecialchars($status) ?>"
                                        <?= $booking['status'] === $status
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        <?= htmlspecialchars(
                                            bookingStatusLabel($status)
                                        ) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                            <button
                                type="submit"
                                name="update_booking_status"
                            >
                                <?= htmlspecialchars(t('update_status')) ?>
                            </button>

                        </form>

                    </article>

                <?php endwhile; ?>

            </section>

        <?php endif; ?>

    </section>

</main>

<?php include "footer.php"; ?>

</body>
</html>

<?php

$bookings_stmt->close();
$conn->close();

?>