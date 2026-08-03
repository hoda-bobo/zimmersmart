<?php

session_start();

include "connection.php";
include "language.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

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
        <?= htmlspecialchars(
            t('booking_confirmed_page_title')
        ) ?>
    </title>

    <link
        rel="stylesheet"
        href="style.css?v=<?= time() ?>"
    >

</head>

<body>

<?php include "navbar.php"; ?>

<main class="booking-confirmation-page">

    <div class="booking-confirmation-container">

        <section class="booking-confirmation-card">

            <div class="booking-confirmation-icon">
                ✓
            </div>

            <h1>
                <?= htmlspecialchars(
                    t('booking_confirmed_title')
                ) ?>
            </h1>

            <p>
                <?= htmlspecialchars(
                    t('booking_confirmed_message')
                ) ?>
            </p>

            <p>
                <?= htmlspecialchars(
                    t('booking_thank_you_message')
                ) ?>
            </p>

            <div class="booking-confirmation-divider"></div>

            <div class="booking-confirmation-actions">

                <a
                    href="mybookings.php"
                    class="booking-confirmation-button"
                >
                    <?= htmlspecialchars(
                        t('view_my_bookings')
                    ) ?>
                </a>

                <a
                    href="home.php"
                    class="booking-confirmation-button secondary"
                >
                    <?= htmlspecialchars(
                        t('back_home')
                    ) ?>
                </a>

            </div>

        </section>

    </div>

</main>

</body>
</html>