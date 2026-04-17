<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
?>

<?php include "navbar.php"; ?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Home</title>

<link rel="stylesheet" href="style.css">
</head>

<body>

<div class="container">

<div class="card">
    <h1>Welcome <?= $_SESSION['name']; ?> 👋</h1>
    <p>You are logged in successfully</p>
</div>

</div>

</body>
</html>