<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

include "connection.php";
include "navbar.php";

$user_id = $_SESSION['user_id'];

/* ===== Filters ===== */
$location = trim($_GET['location'] ?? '');
$guests = filter_var($_GET['guests'] ?? '', FILTER_VALIDATE_INT);
$max_price = filter_var($_GET['max_price'] ?? '', FILTER_VALIDATE_INT);
$selected_services = $_GET['services'] ?? [];

/* ===== SAVE SEARCH ACTIVITY ===== */
if ($location || $guests || $max_price) {
    $stmt = $conn->prepare("
        INSERT INTO user_activity 
        (user_id, action_type, search_location, guests, max_price)
        VALUES (?, 'search', ?, ?, ?)
    ");
    $stmt->bind_param("isid", $user_id, $location, $guests, $max_price);
    $stmt->execute();
}

/* ===== SAVE SEARCHED SERVICES ===== */
if (!empty($selected_services)) {
    foreach ($selected_services as $srv) {
        $srv = intval($srv);
        $stmt = $conn->prepare("
            INSERT INTO search_logs (user_id, service_id)
            VALUES (?, ?)
        ");
        $stmt->bind_param("ii", $user_id, $srv);
        $stmt->execute();
    }
}

/* ===== Locations ===== */
$locations_result = $conn->query("SELECT DISTINCT location FROM cabins ORDER BY location");

/* ===== Services ===== */
$services_array = [];
$res = $conn->query("SELECT service_id, service_name FROM services");
while($row = $res->fetch_assoc()) {
    $services_array[] = $row;
}

/* ===== MAIN QUERY ===== */
$query = "
    SELECT 
        c.*,
        (SELECT image_path FROM cabin_images WHERE cabin_id = c.id LIMIT 1) AS main_image
    FROM cabins c
    WHERE 1=1
";

$params = [];
$types = "";

if (!empty($location)) {
    $query .= " AND c.location = ?";
    $params[] = $location;
    $types .= "s";
}

if ($guests) {
    $query .= " AND c.max_guests >= ?";
    $params[] = $guests;
    $types .= "i";
}

if ($max_price) {
    $query .= " AND c.price_per_night <= ?";
    $params[] = $max_price;
    $types .= "i";
}

if (!empty($selected_services)) {

    $placeholders = implode(',', array_fill(0, count($selected_services), '?'));

    $query .= "
        AND c.id IN (
            SELECT cabin_id
            FROM cabin_services
            WHERE service_id IN ($placeholders)
            GROUP BY cabin_id
            HAVING COUNT(DISTINCT service_id) = ?
        )
    ";

    foreach ($selected_services as $srv) {
        $params[] = (int)$srv;
        $types .= "i";
    }

    $params[] = count($selected_services);
    $types .= "i";
}

/* ===== Execute ===== */
$stmt = $conn->prepare($query);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$total = $result->num_rows;

/* ===== SMART ALTERNATIVE SEARCH ===== */
$is_alternative = false;

if ($total == 0) {

    $service_placeholders = "";
    if (!empty($selected_services)) {
        $service_placeholders = implode(',', array_fill(0, count($selected_services), '?'));
    }

    $alt_query = "
        SELECT 
            c.*,
            (SELECT image_path FROM cabin_images WHERE cabin_id = c.id LIMIT 1) AS main_image,

            (
                (CASE WHEN c.location = ? THEN 30 ELSE 0 END) +
                (CASE WHEN c.max_guests >= ? THEN 20 ELSE 0 END) +
                (CASE WHEN c.price_per_night <= ? THEN 20 ELSE 0 END)
    ";

    if (!empty($selected_services)) {
        $alt_query .= " +
            (
                SELECT COUNT(*) * 10
                FROM cabin_services cs
                WHERE cs.cabin_id = c.id
                AND cs.service_id IN ($service_placeholders)
            )
        ";
    }

    $alt_query .= " +
            (
                SELECT COUNT(*) 
                FROM bookings b 
                WHERE b.cabin_id = c.id AND b.status='confirmed'
            ) * 2
        ) AS score

        FROM cabins c
        ORDER BY score DESC
        LIMIT 6
    ";

    $alt_stmt = $conn->prepare($alt_query);

    $types = "sii";
    $params = [$location ?: '', $guests ?: 0, $max_price ?: 99999];

    if (!empty($selected_services)) {
        foreach ($selected_services as $srv) {
            $params[] = (int)$srv;
            $types .= "i";
        }
    }

    $alt_stmt->bind_param($types, ...$params);
    $alt_stmt->execute();

    $result = $alt_stmt->get_result();
    $total = $result->num_rows;

    $is_alternative = true;
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Search</title>
<link rel="stylesheet" href="style.css">
<link rel="stylesheet" href="search.css">
</head>

<body>

<div class="search-container">

<h1>Find Your Perfect Cabin</h1>

<form method="GET" class="search-box">

<select name="location">
<option value="">All Locations</option>
<?php while($loc = $locations_result->fetch_assoc()) { ?>
<option value="<?= $loc['location'] ?>" <?= $location==$loc['location']?'selected':'' ?>>
<?= $loc['location'] ?>
</option>
<?php } ?>
</select>

<select name="guests">
<option value="">Guests</option>
<option value="2" <?= $guests==2?'selected':'' ?>>2</option>
<option value="4" <?= $guests==4?'selected':'' ?>>4</option>
<option value="6" <?= $guests==6?'selected':'' ?>>6</option>
<option value="8" <?= $guests==8?'selected':'' ?>>8+</option>
</select>

<select name="max_price">
<option value="">Max Price</option>
<option value="200" <?= $max_price==200?'selected':'' ?>>200</option>
<option value="400" <?= $max_price==400?'selected':'' ?>>400</option>
<option value="600" <?= $max_price==600?'selected':'' ?>>600</option>
<option value="800" <?= $max_price==800?'selected':'' ?>>800</option>
</select>

<button type="submit">Search</button>

</form>

<div class="services-filter">
<?php foreach($services_array as $srv) { ?>
<label class="chip">
<input type="checkbox" name="services[]" value="<?= $srv['service_id'] ?>"
<?= in_array($srv['service_id'], $selected_services) ? 'checked' : '' ?>>
<?= $srv['service_name'] ?>
</label>
<?php } ?>
</div>

<?php if($is_alternative) { ?>
<div class="alt-box">
    No exact matches were found.<br>
    Showing the closest available options based on your preferences.
</div>
<?php } ?>

<h2><?= $is_alternative ? "Recommended Results" : "$total Results" ?></h2>

<div class="cards">

<?php while($cabin = $result->fetch_assoc()) { ?>

<div class="card">
<img src="<?= $cabin['main_image'] ?? 'uploads/default.jpg' ?>">
<h3><?= htmlspecialchars($cabin['name']) ?></h3>
<p><?= htmlspecialchars($cabin['location']) ?></p>
<p>$<?= $cabin['price_per_night'] ?></p>
<a href="booking.php?cabin_id=<?= $cabin['id'] ?>">View</a>
</div>

<?php } ?>

</div>

</div>

</body>
</html>