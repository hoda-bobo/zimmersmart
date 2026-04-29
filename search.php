<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

include "connection.php";
include "navbar.php";

 

// Get filter values
$location = trim($_GET['location'] ?? '');
$guests = filter_var($_GET['guests'] ?? '', FILTER_VALIDATE_INT);
$max_price = filter_var($_GET['max_price'] ?? '', FILTER_VALIDATE_INT);

// Get unique locations for dropdown
$locations_query = "SELECT DISTINCT location FROM cabins ORDER BY location";
$locations_result = $conn->query($locations_query);

// Build query with prepared statements
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

if ($guests !== false && $guests > 0) {
    $query .= " AND c.max_guests >= ?";
    $params[] = $guests;
    $types .= "i";
}

if ($max_price !== false && $max_price > 0) {
    $query .= " AND c.price_per_night <= ?";
    $params[] = $max_price;
    $types .= "i";
}

// Execute prepared statement
$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$total = $result->num_rows;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Cabins - <?= $total ?> Results</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="sr-wrap">
    
    <!-- FILTERS SECTION -->
    <div class="filters-container">
        <h2 class="filters-title">Find Your Perfect Cabin</h2>
        
        <form method="GET" action="" class="sr-bar">
            
            <!-- Location Filter -->
            <div class="filter-group">
                <label for="location">📍 Location</label>
                <select name="location" id="location" class="filter-select">
                    <option value="">All Locations</option>
                    <?php while($loc = $locations_result->fetch_assoc()): ?>
                        <option value="<?= htmlspecialchars($loc['location']) ?>" 
                                <?= $location === $loc['location'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($loc['location']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            
            <!-- Guests Filter -->
            <div class="filter-group">
                <label for="guests">👥 Number of Guests</label>
                <select name="guests" id="guests" class="filter-select">
                    <option value="">Any Number</option>
                    <option value="1" <?= $guests === 1 ? 'selected' : '' ?>>1 Guest</option>
                    <option value="2" <?= $guests === 2 ? 'selected' : '' ?>>2 Guests</option>
                    <option value="4" <?= $guests === 4 ? 'selected' : '' ?>>4 Guests</option>
                    <option value="6" <?= $guests === 6 ? 'selected' : '' ?>>6 Guests</option>
                    <option value="8" <?= $guests === 8 ? 'selected' : '' ?>>8+ Guests</option>
                </select>
            </div>
            
            <!-- Price Filter -->
            <div class="filter-group">
                <label for="max_price">💰 Max Price Per Night</label>
                <select name="max_price" id="max_price" class="filter-select">
                    <option value="">Any Price</option>
                    <option value="200" <?= $max_price === 200 ? 'selected' : '' ?>>Up to ₪200</option>
                    <option value="400" <?= $max_price === 400 ? 'selected' : '' ?>>Up to ₪400</option>
                    <option value="600" <?= $max_price === 600 ? 'selected' : '' ?>>Up to ₪600</option>
                    <option value="800" <?= $max_price === 800 ? 'selected' : '' ?>>Up to ₪800</option>
                    <option value="1000" <?= $max_price === 1000 ? 'selected' : '' ?>>Up to ₪1000</option>
                </select>
            </div>
            
            <!-- Action Buttons -->
            <div class="filter-actions">
                <button type="submit" class="btn-search">🔍 Search</button>
                <a href="search.php" class="btn-clear">Clear Filters</a>
            </div>
            
        </form>
        
        <!-- Active Filters Display -->
        <?php if (!empty($location) || $guests || $max_price): ?>
            <div class="active-filters">
                <span class="filter-label">Active Filters:</span>
                <?php if (!empty($location)): ?>
                    <span class="filter-tag">📍 <?= htmlspecialchars($location) ?></span>
                <?php endif; ?>
                <?php if ($guests): ?>
                    <span class="filter-tag">👥 <?= $guests ?>+ Guests</span>
                <?php endif; ?>
                <?php if ($max_price): ?>
                    <span class="filter-tag">💰 Up to ₪<?= $max_price ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Results count -->
    <div class="sr-title">
        <?php if ($total > 0): ?>
            Found <?= $total ?> Cabin<?= $total > 1 ? 's' : '' ?>
        <?php else: ?>
            No Results Found
        <?php endif; ?>
    </div>

    <!-- Results list -->
    <div class="sr-list">
        
        <?php if ($total > 0): ?>
            <?php while($cabin = $result->fetch_assoc()): ?>
                <div class="sr-row">
                    
                    <!-- IMAGE -->
                    <div class="sr-img">
                        <?php 
                            $image = !empty($cabin['main_image']) ? htmlspecialchars($cabin['main_image']) : 'uploads/default.jpg';
                            $alt = htmlspecialchars($cabin['name']) . ' - Cabin Image';
                        ?>
                        <img src="<?= $image ?>" alt="<?= $alt ?>" loading="lazy">
                    </div>
                    
                    <!-- INFO -->
                    <div class="sr-mid">
                        <div class="sr-name"><?= htmlspecialchars($cabin['name'], ENT_QUOTES, 'UTF-8') ?></div>
                        
                        <div class="sr-sub">
                            <span class="info-item">
                                <span class="icon">📍</span>
                                <?= htmlspecialchars($cabin['location'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <span class="divider">•</span>
                            <span class="info-item">
                                <span class="icon">👥</span>
                                Up to <?= (int)$cabin['max_guests'] ?> guests
                            </span>
                        </div>
                        
                        <div class="sr-feat">
                            <?php if (!empty($cabin['amenities'])): ?>
                                <?= htmlspecialchars($cabin['amenities'], ENT_QUOTES, 'UTF-8') ?>
                            <?php else: ?>
                                ✓ WiFi · ✓ Parking · ✓ Air Conditioning
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!empty($cabin['description'])): ?>
                            <div class="sr-desc">
                                <?= htmlspecialchars(mb_substr($cabin['description'], 0, 120), ENT_QUOTES, 'UTF-8') ?>...
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- SIDE -->
                    <div class="sr-side">
                        <div class="price-container">
                            <div class="sr-price">₪<?= number_format((float)$cabin['price_per_night'], 0, '.', ',') ?></div>
                            <div class="price-label">per night</div>
                        </div>
                        <a href="booking.php?cabin_id=<?= (int)$cabin['id'] ?>" class="sr-btn">
                            View Details
                        </a>
                    </div>
                    
                </div>
            <?php endwhile; ?>
            
        <?php else: ?>
            <div class="sr-empty">
                <div class="empty-icon">🏡</div>
                <h3>No Cabins Found</h3>
                <p>Try adjusting your search filters</p>
            </div>
        <?php endif; ?>
        
    </div>

</div>

<?php
// Clean up
$stmt->close();
$conn->close();
?>

<?php include "footer.php"; ?>

</body>
</html>