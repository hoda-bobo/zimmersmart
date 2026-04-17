<?php
session_start();
include "connection.php";
include "navbar.php";

$user_id = $_SESSION['user_id'] ?? 0;

$result = $conn->query("SELECT * FROM favorites WHERE user_id='$user_id'");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Favorites</title>
    <link rel="stylesheet" href="style.css">
</head>

<body>

<div class="page">

    <h2 class="title">My Favorites</h2>

    <div class="favorites-list">

        <?php if ($result && $result->num_rows > 0) { ?>

            <?php while($row = $result->fetch_assoc()) { ?>
                
                <div class="favorite-item">
                    ❤️ <?= $row['item_name'] ?>
                </div>

            <?php } ?>

        <?php } else { ?>

            <p>No favorites yet 💔</p>

        <?php } ?>

    </div>

</div>

</body>
</html>