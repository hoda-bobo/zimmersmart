<?php
session_start();
include "connection.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$owner_id = $_SESSION['user_id'];
$view = $_GET['view'] ?? "overview";
?>

<!DOCTYPE html>
<html>
<head>
    <title>Statistics</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body>

<?php include "navbar.php"; ?>

<div class="edit-page">
<div class="edit-container">

<h1>Statistics Dashboard</h1>

<!-- TABS -->
<div class="stats-tabs">
    <a href="?view=overview" class="<?= $view=='overview'?'active':'' ?>">Overview</a>
    <a href="?view=revenue" class="<?= $view=='revenue'?'active':'' ?>">Revenue</a>
    <a href="?view=services" class="<?= $view=='services'?'active':'' ?>">Services</a>
</div>

<?php
/* ================= OVERVIEW ================= */
if($view == "overview") {

$q1 = $conn->query("
    SELECT COUNT(*) total
    FROM bookings b
    JOIN cabins c ON b.cabin_id = c.id
    WHERE c.owner_id=$owner_id
");

$q2 = $conn->query("
    SELECT SUM(total_price) total
    FROM bookings b
    JOIN cabins c ON b.cabin_id = c.id
    WHERE c.owner_id=$owner_id AND status='confirmed'
");

$total_bookings = $q1 ? $q1->fetch_assoc()['total'] : 0;
$total_revenue = $q2 ? $q2->fetch_assoc()['total'] : 0;
?>

<div class="stats-grid">
    <div class="chart-card">
        <h3>Total Bookings</h3>
        <h1><?= $total_bookings ?></h1>
    </div>

    <div class="chart-card">
        <h3>Total Revenue</h3>
        <h1>$<?= $total_revenue ?? 0 ?></h1>
    </div>
</div>

<?php } ?>

<?php
/* ================= REVENUE ================= */
if($view == "revenue") {

$rev = $conn->query("
    SELECT MONTH(start_date) m, SUM(total_price) total
    FROM bookings b
    JOIN cabins c ON b.cabin_id = c.id
    WHERE c.owner_id=$owner_id AND status='confirmed'
    GROUP BY m
");

if (!$rev) {
    echo "<p style='color:red'>SQL Error: ".$conn->error."</p>";
}
?>

<div class="chart-card">
    <h3>Revenue per Month</h3>
    <canvas id="revChart"></canvas>
</div>

<script>
new Chart(document.getElementById('revChart'), {
    type: 'line',
    data: {
        labels: [
            <?php 
            if($rev){
                while($r=$rev->fetch_assoc()) echo "'".$r['m']."',";
            }
            ?>
        ],
        datasets: [{
            label: 'Revenue',
            data: [
                <?php 
                if($rev){
                    $rev->data_seek(0);
                    while($r=$rev->fetch_assoc()) echo $r['total'].","; 
                }
                ?>
            ]
        }]
    }
});
</script>

<?php } ?>

<?php
/* ================= SERVICES ================= */
if($view == "services") {

/* נבדוק אם יש search_logs */
$check_table = $conn->query("SHOW TABLES LIKE 'search_logs'");
$has_search_logs = ($check_table && $check_table->num_rows > 0);

$sql = "
SELECT 
    s.service_name,

    ".($has_search_logs 
        ? "(SELECT COUNT(*) FROM search_logs sl WHERE sl.service_id = s.service_id)" 
        : "0"
    )." AS searches,

    (SELECT COUNT(*) 
     FROM bookings b
     JOIN cabin_services cs ON b.cabin_id = cs.cabin_id
     WHERE cs.service_id = s.service_id
    ) AS bookings

FROM services s
";

$services_stats = $conn->query($sql);

if (!$services_stats) {
    echo "<p style='color:red'>SQL Error: ".$conn->error."</p>";
}
?>

<h2>Services Performance</h2>

<table class="stats-table">
<tr>
    <th>Service</th>
    <th>Searches</th>
    <th>Bookings</th>
    <th>Conversion %</th>
</tr>

<?php if($services_stats) {
while($row = $services_stats->fetch_assoc()) {

$conversion = ($row['searches'] > 0)
    ? round(($row['bookings'] / $row['searches']) * 100, 1)
    : 0;
?>

<tr>
    <td><?= $row['service_name'] ?></td>
    <td><?= $row['searches'] ?></td>
    <td><?= $row['bookings'] ?></td>
    <td><?= $conversion ?>%</td>
</tr>

<?php }} ?>

</table>

<?php } ?>

</div>
</div>

</body>
</html>