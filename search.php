<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

include "header.php";
include "connection.php";
?>

<a href="search.php" class="btn">search</a>