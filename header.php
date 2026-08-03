<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "language.php";
?>

<link rel="stylesheet" href="style.css">