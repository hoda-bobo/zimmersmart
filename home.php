<?php
session_start();
include "connection.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$query = "
SELECT c.*
FROM cabins c
ORDER BY c.id DESC
";
$result = $conn->query($query);
?>

<?php include "navbar.php"; ?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>ZimmerSmart - Home</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="hero">
    <div class="hero-overlay">
        <div class="hero-content">
            <p class="hero-top">Luxury Cabins Across Israel</p>
            <h1>Find Your Dream Vacation</h1>
            <p class="hero-subtitle">
                Romantic suites, family cabins, sea views and unforgettable experiences
            </p>

            <a href="#cabins-section" class="hero-btn">Explore Cabins</a>
        </div>
    </div>
</div>

<div class="home-intro">
    <h2>Welcome <?= htmlspecialchars($_SESSION['name']); ?> 👋</h2>
    <p>Discover handpicked cabins with comfort, style and beautiful views.</p>
</div>

<div class="section-title" id="cabins-section">
    <h2>Featured Cabins</h2>
    <p>Choose the perfect place for your next vacation</p>
</div>

<div class="cabins-grid">

<?php while($c = $result->fetch_assoc()) { ?>

    <?php
    $cabin_id = $c['id'];
    $images = $conn->query("SELECT image_path FROM cabin_images WHERE cabin_id = $cabin_id");
    ?>

    <div class="cabin-card">

        <div class="slider">
            <?php
            $has_images = false;
            while($img = $images->fetch_assoc()) {
                $has_images = true;
                ?>
                <img src="<?= htmlspecialchars($img['image_path']); ?>" class="slide" alt="Cabin Image">
                <?php
            }

            if (!$has_images) {
                ?>
                <img src="uploads/hero.jpg" class="slide" alt="Default Image">
                <?php
            }
            ?>

            <button class="prev">&#10094;</button>
            <button class="next">&#10095;</button>

            <div class="slider-dots"></div>
        </div>

        <div class="cabin-info">
            <div class="cabin-top-row">
                <h3><?= htmlspecialchars($c['name']); ?></h3>
                <span class="rating">⭐ 4.8</span>
            </div>

            <p class="location">📍 <?= htmlspecialchars($c['location']); ?></p>

            <p class="description">
                <?= htmlspecialchars($c['description']); ?>
            </p>

            <div class="card-bottom">
                <div class="price-box">
                    <span class="amount">$<?= htmlspecialchars($c['price_per_night']); ?></span>
                    <span class="night">/ night</span>
                </div>

                <div class="guests-box">
                    👥 <?= htmlspecialchars($c['max_guests']); ?> guests
                </div>
            </div>

            <div class="card-buttons">
                <a href="booking.php?cabin_id=<?= $c['id']; ?>" class="btn">Book Now</a>
                <a href="favorites.php" class="btn btn-light">Favorite</a>
            </div>
        </div>

    </div>

<?php } ?>

</div>

<div class="why-us">
    <div class="why-card">
        <h3>Luxury Experience</h3>
        <p>Beautiful cabins with premium comfort and relaxing atmosphere.</p>
    </div>

    <div class="why-card">
        <h3>Best Locations</h3>
        <p>From sea views to mountains, discover the most beautiful places.</p>
    </div>

    <div class="why-card">
        <h3>Easy Booking</h3>
        <p>Simple browsing, quick decisions, and smooth booking experience.</p>
    </div>
</div>

<script>
document.querySelectorAll('.slider').forEach(function(slider) {
    let slides = slider.querySelectorAll('.slide');
    let prevBtn = slider.querySelector('.prev');
    let nextBtn = slider.querySelector('.next');
    let dotsContainer = slider.querySelector('.slider-dots');
    let index = 0;

    if (slides.length === 0) return;

    function showSlide(i) {
        slides.forEach(function(slide) {
            slide.style.display = 'none';
        });

        let dots = dotsContainer.querySelectorAll('.dot');
        dots.forEach(function(dot) {
            dot.classList.remove('active-dot');
        });

        slides[i].style.display = 'block';

        if (dots[i]) {
            dots[i].classList.add('active-dot');
        }
    }

    if (slides.length > 1) {
        slides.forEach(function(_, i) {
            let dot = document.createElement('span');
            dot.classList.add('dot');
            dot.addEventListener('click', function() {
                index = i;
                showSlide(index);
            });
            dotsContainer.appendChild(dot);
        });
    }

    showSlide(index);

    nextBtn.addEventListener('click', function() {
        index++;
        if (index >= slides.length) {
            index = 0;
        }
        showSlide(index);
    });

    prevBtn.addEventListener('click', function() {
        index--;
        if (index < 0) {
            index = slides.length - 1;
        }
        showSlide(index);
    });
});
</script>
<?php include "footer.php"; ?>
</body>
</html>