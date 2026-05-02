<?php
session_start();
include "connection.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
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
")->fetch_assoc()['total'];

/* גרף חודשי */
$monthly_stats = runQuery($conn, "
    SELECT MONTH(start_date) m, COUNT(*) bookings, SUM(total_price) revenue
    FROM bookings b
    JOIN cabins c ON b.cabin_id = c.id
    WHERE c.owner_id = $owner_id
    GROUP BY m
");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Dashboard</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body>

<?php include "navbar.php"; ?>

<div class="owner-container">

<h1>Dashboard</h1>

<div style="display:flex; gap:10px;">
    <a href="add_cabins.php" class="owner-main-btn">Add Cabin</a>
    <a href="statistics.php" class="owner-main-btn">Statistics</a>
</div>

<div class="stats-grid">

    <div class="chart-card">
        <h3>Total Cabins</h3>
        <h1><?= $total_cabins ?></h1>
    </div>

    <div class="chart-card">
        <h3>Total Bookings</h3>
        <h1><?= $total_bookings ?></h1>
    </div>

    <div class="chart-card">
        <h3>Total Revenue</h3>
        <h1>$<?= $monthly_revenue ?? 0 ?></h1>
    </div>

</div>

<div class="chart-card">
    <h3>Monthly Analytics</h3>
    <canvas id="chart"></canvas>
</div>

<script>
new Chart(document.getElementById('chart'), {
    type: 'line',
    data: {
        labels: [
            <?php while($m=$monthly_stats->fetch_assoc()) echo "'".$m['m']."',"; ?>
        ],
        datasets: [
            {
                label: 'Bookings',
                data: [
                    <?php $monthly_stats->data_seek(0);
                    while($m=$monthly_stats->fetch_assoc()) echo $m['bookings'].","; ?>
                ]
            },
            {
                label: 'Revenue',
                data: [
                    <?php $monthly_stats->data_seek(0);
                    while($m=$monthly_stats->fetch_assoc()) echo $m['revenue'].","; ?>
                ]
            }
        ]
    }
});
</script>

</body>
</html>