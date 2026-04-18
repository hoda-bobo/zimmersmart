<div class="navbar">

    <div class="logo">ZimmerSmart</div>

    <div class="links">

        <a href="home.php">Home</a>

        <?php if (isset($_SESSION['user_id'])) { ?>

            <a href="search.php">Search</a>
            <a href="booking.php">Booking</a>
            <a href="favorites.php">Favorites</a>
            <a href="profile.php">Profile</a>

            <span class="hello">
                Hi, <?= $_SESSION['first_name'] ?? '' ?>
                <?= $_SESSION['last_name'] ?? '' ?>
            </span>

            <a href="logout.php">Logout</a>

        <?php } else { ?>

            <a href="login.php">Login</a>
            <a href="register.php">Register</a>

        <?php } ?>

    </div>
</div>