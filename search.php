<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

include "connection.php";
include "navbar.php";

/* קלטים */
$location = $_GET['location'] ?? '';
$guests = $_GET['guests'] ?? '';
$max_price = $_GET['max_price'] ?? '';
$services = $_GET['services'] ?? [];
$sort = $_GET['sort'] ?? '';

/* שאילתה */
$query = "
SELECT c.*,
(SELECT image_path FROM cabin_images WHERE cabin_id = c.id LIMIT 1) AS main_image
FROM cabins c
LEFT JOIN cabin_services cs ON c.id = cs.cabin_id
WHERE 1=1
";

if ($location != '') {
    $query .= " AND c.location LIKE '%$location%' ";
}

if ($guests != '') {
    $query .= " AND c.max_guests >= $guests ";
}

if ($max_price != '') {
    $query .= " AND c.price_per_night <= $max_price ";
}

if (!empty($services)) {
    $ids = implode(',', array_map('intval', $services));
    $query .= " AND cs.service_id IN ($ids) ";
}

$query .= " GROUP BY c.id ";

/* מיון */
if ($sort == "price_low") {
    $query .= " ORDER BY c.price_per_night ASC ";
} elseif ($sort == "price_high") {
    $query .= " ORDER BY c.price_per_night DESC ";
}

/* הרצה */
$result = $conn->query($query);
$total_results = $result->num_rows;

/* שירותים */
$all_services = $conn->query("SELECT * FROM services");
?>

<!DOCTYPE html>
<html>
<head>
<title>Search</title>
<link rel="stylesheet" href="style.css">
</head>

<body>

<!-- HERO -->
<div class="hero">
    <div class="hero-content">
        <h1>Find your perfect stay</h1>
        <p>Luxury cabins, nature & comfort</p>
    </div>
</div>

<div class="search-page">

<!-- FILTERS -->
<form method="GET" class="filters">

<h3>Filters</h3>

<div class="filter-section">
    <h4>Max Price</h4>
    <input type="range" name="max_price" min="100" max="5000"
    oninput="priceText.innerText='$'+this.value">
    <p id="priceText">$100 - $5000+</p>
</div>

<hr>

<div class="filter-section">
    <h4>Facilities</h4>

    <?php while($s = $all_services->fetch_assoc()) { ?>
        <label class="filter-item">
            <input type="checkbox" name="services[]" value="<?= $s['service_id'] ?>">
            <span class="checkmark"></span>
            <?= $s['service_name'] ?>
        </label>
    <?php } ?>

</div>

<select name="sort">
    <option value="">Sort</option>
    <option value="price_low">Price low → high</option>
    <option value="price_high">Price high → low</option>
</select>

<button class="search-btn">Search</button>

</form>

<!-- RESULTS SIDE -->
<div>

<!-- 🔥 RESULT BAR -->
<div class="results-bar">
    <h3><?= $total_results ?> results found</h3>
</div>

<!-- RESULTS -->
<div class="results">

<?php if ($total_results > 0) { ?>

<?php while($c = $result->fetch_assoc()) { ?>

<div class="cabin-card">

    <img src="<?= $c['main_image'] ? $c['main_image'] : 'uploads/default.jpg' ?>" class="card-img">

    <div class="card-body">

        <h3><?= htmlspecialchars($c['name']) ?></h3>

        <p class="location">📍 <?= htmlspecialchars($c['location']) ?></p>

        <div class="price-box">
            <span class="price">$<?= $c['price_per_night'] ?></span>
            <span class="night">/ night</span>
        </div>

        <a href="booking.php?cabin_id=<?= $c['id'] ?>" class="btn">
            View
        </a>

    </div>

</div>

<?php } ?>

<?php } else { ?>

<!-- 🔥 EMPTY STATE -->
<div class="empty">
    <h2>No results found</h2>
    <p>Try changing filters or explore other cabins</p>
</div>

<?php } ?>

</div>

</div>

</div>

</body>
</html>