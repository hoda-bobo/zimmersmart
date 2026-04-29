<div class="navbar">

    <div class="logo">ZimmerSmart</div>

    <div class="links">

        <a href="home.php">Home</a>

        <?php if (isset($_SESSION['user_id'])) { ?>

            <a href="search.php">Search</a>
            <a href="favorites.php">Favorites</a>

            <!-- רק OWNER + CUSTOMER -->
            <?php if ($_SESSION['user_type'] == 'owner' || $_SESSION['user_type'] == 'customer') { ?>
                <a href="dashboard.php">Dashboard</a>
            <?php } ?>

            <a href="profile.php">Profile</a>

            <span class="hello">
                Hi, <?= htmlspecialchars($_SESSION['first_name'] ?? '') ?>
                <?= htmlspecialchars($_SESSION['last_name'] ?? '') ?>
            </span>

            <a href="logout.php">Logout</a>

        <?php } else { ?>

            <a href="login.php">Login</a>
            <a href="register.php">Register</a>

        <?php } ?>

    </div>

</div>