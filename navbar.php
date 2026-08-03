<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/language.php';

$next_language = current_language() === 'he' ? 'en' : 'he';
?>

<script>
document.documentElement.lang = <?= json_encode(current_language()) ?>;
document.documentElement.dir = <?= json_encode(is_rtl() ? 'rtl' : 'ltr') ?>;

document.addEventListener('DOMContentLoaded', function () {
    document.body.classList.toggle('rtl-site', <?= is_rtl() ? 'true' : 'false' ?>);
});
</script>

<div class="navbar <?= is_rtl() ? 'navbar-rtl' : 'navbar-ltr' ?>">

    <div class="logo">
        <?= htmlspecialchars(t('site_name')) ?>
    </div>

    <div class="links">

        <a href="home.php">
            <?= htmlspecialchars(t('home')) ?>
        </a>

        <?php if (isset($_SESSION['user_id'])) { ?>

            <?php if (($_SESSION['user_type'] ?? '') === 'customer') { ?>

                <a href="search.php">
                    <?= htmlspecialchars(t('search')) ?>
                </a>

                <a href="mybookings.php">
                    <?= htmlspecialchars(t('my_bookings')) ?>
                </a>

                <a href="request_role.php">
                    <?= htmlspecialchars(t('become_partner')) ?>
                </a>

            <?php } ?>

            <?php if (($_SESSION['user_type'] ?? '') === 'owner') { ?>

            

                <a href="managecabins.php">
                    <?= htmlspecialchars(t('manage_cabins')) ?>
                </a>

            <?php } ?>

            <?php if (($_SESSION['user_type'] ?? '') === 'attraction_owner') { ?>

                <a href="manageattractions.php">
                    <?= htmlspecialchars(t('manage_attractions')) ?>
                </a>

            <?php } ?>

            <?php if (($_SESSION['user_type'] ?? '') === 'admin') { ?>

                <a href="admin_dashboard.php">
                    <?= htmlspecialchars(t('admin_dashboard')) ?>
                </a>

            <?php } ?>

            <a href="profile.php">
                <?= htmlspecialchars(t('profile')) ?>
            </a>

            <span class="hello">

                <?= htmlspecialchars(t('hello')) ?>,

                <?= htmlspecialchars($_SESSION['first_name'] ?? '') ?>

                <?= htmlspecialchars($_SESSION['last_name'] ?? '') ?>

            </span>

            <a href="logout.php">
                <?= htmlspecialchars(t('logout')) ?>
            </a>

        <?php } else { ?>

            <a href="login.php">
                <?= htmlspecialchars(t('login')) ?>
            </a>

            <a href="register.php">
                <?= htmlspecialchars(t('register')) ?>
            </a>

        <?php } ?>

        <a
            href="<?= htmlspecialchars(language_switch_url($next_language)) ?>"
            class="language-btn"
            title="<?= htmlspecialchars(t('language_title')) ?>"
        >
            🌐 <?= htmlspecialchars(t('language_button')) ?>
        </a>

    </div>

</div>