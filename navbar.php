<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>

<div class="navbar">

    <div class="logo">ZimmerSmart</div>

    <div class="links">

        <a href="home.php">Home</a>
        <a href="search.php">Search</a>
        <a href="booking.php">Booking</a>
        <a href="favorites.php">Favorites</a>

        <?php if (isset($_SESSION['user_id'])) { ?>
            <span class="hello">Hi, <?= $_SESSION['name'] ?></span>
            <a class="logout" href="logout.php">Logout</a>
        <?php } else { ?>
            <a href="login.php">Login</a>
            <a href="register.php">Register</a>
        <?php } ?>

    </div>
</div>

