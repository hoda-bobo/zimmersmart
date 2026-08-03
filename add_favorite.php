<?php
session_start();
include "connection.php";
include "lead_helper.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$item = $_GET['item'];

$user_id = $_SESSION['user_id'];

$conn->query("INSERT INTO favorites (user_id, item_name)
VALUES ('$user_id', '$item')");

header("Location: favorites.php");
exit();
?>