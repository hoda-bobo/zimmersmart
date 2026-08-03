<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include "connection.php";
include "lead_helper.php";
include "language.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

/* Get cabins ordered by rating */
$query = "
    SELECT
        c.*,
        COALESCE(AVG(r.rating), 0) AS avg_rating,
        COUNT(r.review_id) AS total_reviews
    FROM cabins c
    LEFT JOIN reviews r
        ON c.id = r.cabin_id
    GROUP BY c.id
    ORDER BY avg_rating DESC, total_reviews DESC
";

$result = $conn->query($query);

if (!$result) {
    die("CABINS SQL ERROR: " . $conn->error);
}

$current_language = current_language();
?>

<!DOCTYPE html>

<html
    lang="<?= htmlspecialchars($current_language) ?>"
    dir="<?= is_rtl() ? 'rtl' : 'ltr' ?>"
>

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        <?= htmlspecialchars(t('home_page_title')) ?>
    </title>

    <link
        rel="stylesheet"
        href="style.css?v=<?= time(); ?>"
    >

</head>

<body class="<?= is_rtl() ? 'rtl-site' : 'ltr-site' ?>">

<?php include "navbar.php"; ?>

<main>

    <!-- HERO -->

    <section class="hero">

        <div class="hero-overlay">

            <div class="hero-content">

                <span class="hero-label">
                    <?= htmlspecialchars(t('hero_label')) ?>
                </span>

                <h1>
                    <?= htmlspecialchars(t('hero_title')) ?>
                </h1>

                <p>
                    <?= htmlspecialchars(t('hero_description')) ?>
                </p>

                <a
                    href="search.php"
                    class="hero-btn"
                >
                    <?= htmlspecialchars(t('explore_cabins')) ?>
                </a>

            </div>

        </div>

    </section>

    <!-- INTRO -->

    <section class="home-intro">

        <span class="section-eyebrow">
            <?= htmlspecialchars(t('exclusive_collection')) ?>
        </span>

        <h2>
            <?= htmlspecialchars(t('home_intro_title')) ?>
        </h2>

        <p>
            <?= htmlspecialchars(t('home_intro_description')) ?>
        </p>

    </section>

    <!-- CABINS TITLE -->

    <section
        class="section-title"
        id="cabins-section"
    >

        <span class="section-eyebrow">
            <?= htmlspecialchars(t('guest_favorites')) ?>
        </span>

        <h2>
            <?= htmlspecialchars(t('choose_by_rating')) ?>
        </h2>

        <p>
            <?= htmlspecialchars(t('highest_rated_description')) ?>
        </p>

    </section>

    <!-- CABINS GRID -->

    <section class="cabins-grid">

        <?php if ($result->num_rows > 0): ?>

            <?php while ($cabin = $result->fetch_assoc()): ?>

                <?php

                $cabin_id = (int) $cabin['id'];

                /*
                 * We do not use ORDER BY id because the table may not
                 * contain a column named id.
                 */
                $image_statement = $conn->prepare("
                    SELECT image_path
                    FROM cabin_images
                    WHERE cabin_id = ?
                ");

                if (!$image_statement) {
                    die("IMAGE SQL ERROR: " . $conn->error);
                }

                $image_statement->bind_param("i", $cabin_id);
                $image_statement->execute();

                $images = $image_statement->get_result();
                ?>

                <article class="cabin-card">

                    <div class="slider">

                        <?php $has_images = false; ?>

                        <?php while ($image = $images->fetch_assoc()): ?>

                            <?php $has_images = true; ?>

                            <img
                                src="<?= htmlspecialchars($image['image_path']) ?>"
                                class="slide"
                                alt="<?= htmlspecialchars($cabin['name']) ?>"
                            >

                        <?php endwhile; ?>

                        <?php if (!$has_images): ?>

                            <img
                                src="uploads/default.jpg"
                                class="slide"
                                alt="<?= htmlspecialchars($cabin['name']) ?>"
                            >

                        <?php endif; ?>

                        <button
                            type="button"
                            class="prev"
                            aria-label="<?= htmlspecialchars(t('previous_image')) ?>"
                        >
                            &#10094;
                        </button>

                        <button
                            type="button"
                            class="next"
                            aria-label="<?= htmlspecialchars(t('next_image')) ?>"
                        >
                            &#10095;
                        </button>

                        <div class="slider-dots"></div>

                    </div>

                    <div class="cabin-info">

                        <div class="cabin-top-row">

                            <div class="cabin-heading">

                                <h3>
                                    <?= htmlspecialchars($cabin['name']) ?>
                                </h3>

                                <p class="location">
                                    <?= htmlspecialchars($cabin['location']) ?>
                                </p>

                            </div>

                            <span class="rating">

                                <?= number_format(
                                    (float) $cabin['avg_rating'],
                                    1
                                ) ?>

                                <small>
                                    <?= (int) $cabin['total_reviews'] ?>
                                    <?= htmlspecialchars(t('reviews')) ?>
                                </small>

                            </span>

                        </div>

                        <p class="description">
                            <?= htmlspecialchars($cabin['description']) ?>
                        </p>

                        <div class="card-bottom">

                            <div class="price-box">

                                <span class="amount">
                                    ₪<?= number_format(
                                        (float) $cabin['price_per_night'],
                                        0
                                    ) ?>
                                </span>

                                <span class="night">
                                    <?= htmlspecialchars(t('per_night')) ?>
                                </span>

                            </div>

                            <div class="guests-box">

                                <?= (int) $cabin['max_guests'] ?>

                                <?= htmlspecialchars(t('guests')) ?>

                            </div>

                        </div>

                        <div class="card-buttons">

                            <a
                                href="booking.php?cabin_id=<?= $cabin_id ?>"
                                class="btn"
                            >
                                <?= htmlspecialchars(t('book_now')) ?>
                            </a>

                        </div>

                    </div>

                </article>

                <?php $image_statement->close(); ?>

            <?php endwhile; ?>

        <?php else: ?>

            <div class="sr-empty">

                <h3>
                    <?= htmlspecialchars(t('no_cabins_found')) ?>
                </h3>

            </div>

        <?php endif; ?>

    </section>

    <!-- BENEFITS -->

    <section class="luxury-benefits">

        <article class="luxury-benefit">

            <span class="benefit-number">
                01
            </span>

            <h3>
                <?= htmlspecialchars(t('luxury_experience')) ?>
            </h3>

            <p>
                <?= htmlspecialchars(
                    t('luxury_experience_description')
                ) ?>
            </p>

        </article>

        <article class="luxury-benefit">

            <span class="benefit-number">
                02
            </span>

            <h3>
                <?= htmlspecialchars(t('best_locations')) ?>
            </h3>

            <p>
                <?= htmlspecialchars(
                    t('best_locations_description')
                ) ?>
            </p>

        </article>

        <article class="luxury-benefit">

            <span class="benefit-number">
                03
            </span>

            <h3>
                <?= htmlspecialchars(t('easy_booking')) ?>
            </h3>

            <p>
                <?= htmlspecialchars(
                    t('easy_booking_description')
                ) ?>
            </p>

        </article>

    </section>

</main>

<?php include "footer.php"; ?>

<script>

document.querySelectorAll('.slider').forEach(function (slider) {

    const slides = slider.querySelectorAll('.slide');
    const previousButton = slider.querySelector('.prev');
    const nextButton = slider.querySelector('.next');
    const dotsContainer = slider.querySelector('.slider-dots');

    let currentIndex = 0;

    if (slides.length === 0) {
        return;
    }

    function showSlide(index) {

        slides.forEach(function (slide) {
            slide.style.display = 'none';
        });

        const dots = dotsContainer.querySelectorAll('.dot');

        dots.forEach(function (dot) {
            dot.classList.remove('active-dot');
        });

        slides[index].style.display = 'block';

        if (dots[index]) {
            dots[index].classList.add('active-dot');
        }
    }

    if (slides.length > 1) {

        slides.forEach(function (_, index) {

            const dot = document.createElement('button');

            dot.type = 'button';
            dot.className = 'dot';

            dot.setAttribute(
                'aria-label',
                'Image ' + (index + 1)
            );

            dot.addEventListener('click', function () {

                currentIndex = index;
                showSlide(currentIndex);

            });

            dotsContainer.appendChild(dot);
        });

    } else {

        previousButton.style.display = 'none';
        nextButton.style.display = 'none';
        dotsContainer.style.display = 'none';
    }

    showSlide(currentIndex);

    nextButton.addEventListener('click', function () {

        currentIndex++;

        if (currentIndex >= slides.length) {
            currentIndex = 0;
        }

        showSlide(currentIndex);
    });

    previousButton.addEventListener('click', function () {

        currentIndex--;

        if (currentIndex < 0) {
            currentIndex = slides.length - 1;
        }

        showSlide(currentIndex);
    });

});

</script>

</body>
</html>

<?php
$conn->close();
?>