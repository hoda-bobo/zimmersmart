<?php
$conn = new mysqli("localhost", "root", "", "zimmersmart");

if ($conn->connect_error) {
    die("DB Error");
}
?>