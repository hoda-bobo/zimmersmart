<?php
session_start();
include "connection.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if ($_SESSION['user_type'] != 'owner') {
    die("Only owner can access this page");
}

$owner_id = $_SESSION['user_id'];

function runQuery($conn, $sql) {
    $result = $conn->query($sql);
    if (!$result) {
        die("SQL ERROR: " . $conn->error);
    }
    return $result;
}

/* STATS */
$total_cabins = runQuery($conn, "
    SELECT COUNT(*) AS total 
    FROM cabins 
    WHERE owner_id = $owner_id
")->fetch_assoc()['total'];

$total_bookings = runQuery($conn, "
    SELECT COUNT(*) AS total
    FROM bookings b
    JOIN cabins c ON b.cabin_id = c.id
    WHERE c.owner_id = $owner_id
")->fetch_assoc()['total'];

$monthly_revenue = runQuery($conn, "
    SELECT SUM(b.total_price) AS total
    FROM bookings b
    JOIN cabins c ON b.cabin_id = c.id
    WHERE c.owner_id = $owner_id
    AND b.status = 'confirmed'
")->fetch_assoc()['total'];

if ($monthly_revenue == null) {
    $monthly_revenue = 0;
}

$avg_rating = runQuery($conn, "
    SELECT AVG(r.rating) AS avg_rating
    FROM reviews r
    JOIN cabins c ON r.cabin_id = c.id
    WHERE c.owner_id = $owner_id
")->fetch_assoc()['avg_rating'];

if ($avg_rating == null) {
    $avg_rating = 0;
}

/* CABINS */
$cabins = runQuery($conn, "
    SELECT c.*,
    (
        SELECT image_path 
        FROM cabin_images 
        WHERE cabin_id = c.id 
        LIMIT 1
    ) AS main_image,
    (
        SELECT AVG(rating)
        FROM reviews
        WHERE cabin_id = c.id
    ) AS rating
    FROM cabins c
    WHERE c.owner_id = $owner_id
    ORDER BY c.id DESC
");

/* BOOKINGS */
$bookings = runQuery($conn, "
    SELECT b.*, 
           c.name AS cabin_name,
           u.first_name,
           u.last_name
    FROM bookings b
    JOIN cabins c ON b.cabin_id = c.id
    JOIN users u ON b.user_id = u.id
    WHERE c.owner_id = $owner_id
    ORDER BY b.start_date DESC
");

/* REVIEWS */
$reviews = runQuery($conn, "
    SELECT r.*, 
           c.name AS cabin_name,
           u.first_name,
           u.last_name
    FROM reviews r
    JOIN cabins c ON r.cabin_id = c.id
    JOIN users u ON r.user_id = u.id
    WHERE c.owner_id = $owner_id
    ORDER BY r.review_date DESC
    LIMIT 5
");
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Owner Dashboard</title>

    <link rel="stylesheet" href="style.css">

    <!-- Icons + Charts -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body>

<?php include "navbar.php"; ?>

<div class="owner-dashboard-page">

    <div class="owner-container">

        <div class="owner-header">
            <div>
                <h1>Dashboard Overview</h1>
                <p>Welcome back, <?= htmlspecialchars($_SESSION['first_name'] ?? '') ?>. Manage your cabins, bookings and revenue.</p>
            </div>

            <a href="add_cabins.php" class="owner-main-btn">
                <i class="fas fa-plus"></i>
                Add New Cabin
            </a>
        </div>

        <!-- STATS -->
        <div class="owner-stats-grid">

            <div class="owner-stat-card">
                <div class="owner-stat-icon purple">
                    <i class="fas fa-home"></i>
                </div>
                <h2><?= $total_cabins ?></h2>
                <p>Total Cabins</p>
            </div>

            <div class="owner-stat-card">
                <div class="owner-stat-icon pink">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <h2><?= $total_bookings ?></h2>
                <p>Total Bookings</p>
            </div>

            <div class="owner-stat-card">
                <div class="owner-stat-icon blue">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <h2>$<?= number_format($monthly_revenue, 2) ?></h2>
                <p>Revenue</p>
            </div>

            <div class="owner-stat-card">
                <div class="owner-stat-icon yellow">
                    <i class="fas fa-star"></i>
                </div>
                <h2><?= number_format($avg_rating, 1) ?></h2>
                <p>Average Rating</p>
            </div>

        </div>

        <!-- CABINS -->
        <div class="owner-section-header">
            <h2>Your Cabins</h2>
            <p>Manage each cabin separately</p>
        </div>

        <?php if ($cabins->num_rows == 0) { ?>

            <div class="owner-empty-box">
                <h3>No cabins found</h3>
                <p>You do not have cabins connected to your owner account yet.</p>
                <a href="add_cabins.php" class="owner-main-btn">Add Cabin</a>
            </div>

        <?php } else { ?>

            <div class="owner-cabins-grid">

                <?php while($cabin = $cabins->fetch_assoc()) { ?>

                    <?php
                    $image = $cabin['main_image'];
                    if ($image == "" || $image == null) {
                        $image = "uploads/hero.jpg";
                    }

                    $rating = $cabin['rating'];
                    if ($rating == null) {
                        $rating = 0;
                    }
                    ?>

                    <div class="owner-cabin-card">

                        <div class="owner-cabin-image">
                            <img src="<?= htmlspecialchars($image) ?>" alt="Cabin image">

                            <div class="owner-rating-badge">
                                <i class="fas fa-star"></i>
                                <?= number_format($rating, 1) ?>
                            </div>
                        </div>

                        <div class="owner-cabin-content">

                            <h3><?= htmlspecialchars($cabin['name']) ?></h3>

                            <div class="owner-cabin-location">
                                <i class="fas fa-map-marker-alt"></i>
                                <?= htmlspecialchars($cabin['location']) ?>
                            </div>

                            <div class="owner-cabin-info">
                                <div>
                                    <span class="owner-price">$<?= htmlspecialchars($cabin['price_per_night']) ?></span>
                                    <span class="owner-night">/ night</span>
                                </div>

                                <div class="owner-guests">
                                    <i class="fas fa-users"></i>
                                    <?= htmlspecialchars($cabin['max_guests']) ?> guests
                                </div>
                            </div>

                            <div class="owner-cabin-actions">

                                <a href="cabindetails.php?cabin_id=<?= $cabin['id'] ?>" class="owner-action-btn primary">
                                    <i class="fas fa-eye"></i>
                                    View
                                </a>

                                <a href="edit_cabin.php?id=<?= $cabin['id'] ?>" class="owner-action-btn secondary">
                                    <i class="fas fa-edit"></i>
                                    Edit
                                </a>

                                <a href="pricingavailability.php?id=<?= $cabin['id'] ?>" class="owner-action-btn secondary">
                                    <i class="fas fa-calendar"></i>
                                    Availability
                                </a>

                                <a href="bookingsmanagement.php?cabin_id=<?= $cabin['id'] ?>" class="owner-action-btn secondary">
                                    <i class="fas fa-list"></i>
                                    Bookings
                                </a>

                            </div>

                        </div>

                    </div>

                <?php } ?>

            </div>

        <?php } ?>

        <!-- BOOKINGS -->
        <div class="owner-section-header">
            <h2>Recent Bookings</h2>
            <p>Track all bookings for your cabins</p>
        </div>

        <div class="owner-table-box">
            <table>
                <thead>
                    <tr>
                        <th>Guest</th>
                        <th>Cabin</th>
                        <th>Check-in</th>
                        <th>Check-out</th>
                        <th>Total Price</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($bookings->num_rows == 0) { ?>

                        <tr>
                            <td colspan="6">No bookings yet</td>
                        </tr>

                    <?php } else { ?>

                        <?php while($b = $bookings->fetch_assoc()) { ?>

                            <tr>
                                <td class="bold">
                                    <?= htmlspecialchars($b['first_name'] . " " . $b['last_name']) ?>
                                </td>

                                <td><?= htmlspecialchars($b['cabin_name']) ?></td>

                                <td><?= htmlspecialchars($b['start_date']) ?></td>

                                <td><?= htmlspecialchars($b['end_date']) ?></td>

                                <td class="bold">
                                    $<?= htmlspecialchars($b['total_price']) ?>
                                </td>

                                <td>
                                    <span class="owner-badge <?= htmlspecialchars($b['status']) ?>">
                                        <?= htmlspecialchars($b['status']) ?>
                                    </span>
                                </td>
                            </tr>

                        <?php } ?>

                    <?php } ?>
                </tbody>
            </table>
        </div>

        <!-- ANALYTICS + REVIEWS -->
        <div class="owner-two-grid">

            <div class="owner-chart-card">
                <h2>Bookings Analytics</h2>
                <canvas id="bookingsChart"></canvas>
            </div>

            <div class="owner-reviews-card">
                <h2>Recent Reviews</h2>

                <?php if ($reviews->num_rows == 0) { ?>

                    <p>No reviews yet</p>

                <?php } else { ?>

                    <?php while($r = $reviews->fetch_assoc()) { ?>

                        <div class="owner-review-item">

                            <div class="owner-review-avatar">
                                <?= strtoupper(substr($r['first_name'], 0, 1)) ?>
                            </div>

                            <div>
                                <h4>
                                    <?= htmlspecialchars($r['first_name'] . " " . $r['last_name']) ?>
                                </h4>

                                <div class="owner-stars">
                                    <?php for($i = 1; $i <= 5; $i++) { ?>
                                        <?php if ($i <= $r['rating']) { ?>
                                            <i class="fas fa-star active"></i>
                                        <?php } else { ?>
                                            <i class="fas fa-star"></i>
                                        <?php } ?>
                                    <?php } ?>
                                </div>

                                <p><?= htmlspecialchars($r['comment']) ?></p>

                                <small>
                                    <?= htmlspecialchars($r['cabin_name']) ?> |
                                    <?= htmlspecialchars($r['review_date']) ?>
                                </small>
                            </div>

                        </div>

                    <?php } ?>

                <?php } ?>
            </div>

        </div>

    </div>

</div>

<script>
const ctx = document.getElementById('bookingsChart');

new Chart(ctx, {
    type: 'bar',
    data: {
        labels: ['Cabins', 'Bookings', 'Revenue'],
        datasets: [{
            label: 'Dashboard Data',
            data: [
                <?= $total_cabins ?>,
                <?= $total_bookings ?>,
                <?= $monthly_revenue ?>
            ],
            backgroundColor: [
                'rgba(168, 85, 247, 0.8)',
                'rgba(236, 72, 153, 0.8)',
                'rgba(59, 130, 246, 0.8)'
            ],
            borderRadius: 10
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false }
        }
    }
});
</script>

</body>
</html>