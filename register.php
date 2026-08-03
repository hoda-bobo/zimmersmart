<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include "connection.php";
include "language.php";

$message = "";

if (isset($_POST['register'])) {

    $first_name = trim($_POST['first_name'] ?? "");
    $last_name  = trim($_POST['last_name'] ?? "");
    $email      = trim($_POST['email'] ?? "");
    $password   = $_POST['password'] ?? "";
    $phone      = trim($_POST['phone'] ?? "");

    $user_type = "customer";

    if (
        $first_name === "" ||
        $last_name === "" ||
        $email === "" ||
        $password === ""
    ) {

        $message = t('fill_required_fields');

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = t('invalid_email');

    } elseif (strlen($password) < 6) {

        $message = t('password_min_length');

    } else {

        $check = $conn->prepare("
            SELECT id
            FROM users
            WHERE email = ?
            LIMIT 1
        ");

        if (!$check) {
            die("SQL ERROR: " . $conn->error);
        }

        $check->bind_param("s", $email);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {

            $message = t('email_exists');

        } else {

            $hashed_password = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            $stmt = $conn->prepare("
                INSERT INTO users
                (
                    first_name,
                    last_name,
                    email,
                    password,
                    user_type,
                    phone
                )
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            if (!$stmt) {
                die("SQL ERROR: " . $conn->error);
            }

            $stmt->bind_param(
                "ssssss",
                $first_name,
                $last_name,
                $email,
                $hashed_password,
                $user_type,
                $phone
            );

            if ($stmt->execute()) {

                $stmt->close();
                $check->close();

                header("Location: login.php?registered=1");
                exit();

            } else {

                $message = t('registration_error');
            }

            $stmt->close();
        }

        $check->close();
    }
}

?>

<!DOCTYPE html>
<html
    lang="<?= htmlspecialchars($_SESSION['lang'] ?? 'en') ?>"
    dir="<?= ($_SESSION['lang'] ?? 'en') === 'he' ? 'rtl' : 'ltr' ?>"
>

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title><?= t('register') ?></title>

    <link
        rel="stylesheet"
        href="style.css?v=<?= time(); ?>"
    >

</head>

<body class="register-page">

<?php include "navbar.php"; ?>

<div class="container">

    <div class="card">

        <h2><?= t('register') ?></h2>

        <?php if ($message !== ""): ?>

            <div class="msg">
                <?= htmlspecialchars($message) ?>
            </div>

        <?php endif; ?>

        <form method="POST" autocomplete="off">

            <input
                type="text"
                name="fake_user"
                style="display:none"
            >

            <input
                type="password"
                name="fake_pass"
                style="display:none"
            >

            <input
                type="text"
                name="first_name"
                placeholder="<?= t('first_name') ?>"
                value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>"
                required
            >

            <input
                type="text"
                name="last_name"
                placeholder="<?= t('last_name') ?>"
                value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>"
                required
            >

            <input
                type="email"
                name="email"
                placeholder="<?= t('email') ?>"
                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                required
            >

            <input
                type="text"
                name="phone"
                placeholder="<?= t('phone') ?>"
                value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
            >

            <input
                type="password"
                name="password"
                placeholder="<?= t('password') ?>"
                autocomplete="new-password"
                readonly
                onfocus="this.removeAttribute('readonly');"
                required
            >

            <button
                type="submit"
                name="register"
            >
                <?= t('register') ?>
            </button>

            <div class="login-link">

                <?= t('already_have_account') ?>

                <a href="login.php">
                    <?= t('login') ?>
                </a>

            </div>

        </form>

    </div>

</div>

</body>
</html>