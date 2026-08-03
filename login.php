<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include "connection.php";
include "language.php";

$message = "";

if (isset($_GET['registered'])) {
    $message = t('registration_success');
}

if (isset($_POST['login'])) {

    $email = trim($_POST['email'] ?? "");
    $password = $_POST['password'] ?? "";

    if ($email === "" || $password === "") {

        $message = t('enter_email_password');

    } else {

        $stmt = $conn->prepare("
            SELECT
                id,
                first_name,
                last_name,
                password,
                user_type
            FROM users
            WHERE email = ?
            LIMIT 1
        ");

        if (!$stmt) {
            die("SQL ERROR: " . $conn->error);
        }

        $stmt->bind_param("s", $email);
        $stmt->execute();

        $result = $stmt->get_result();

        if ($user = $result->fetch_assoc()) {

            if (password_verify($password, $user['password'])) {

                session_regenerate_id(true);

                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['last_name'] = $user['last_name'];
                $_SESSION['name'] = $user['first_name'] . " " . $user['last_name'];
                $_SESSION['user_type'] = $user['user_type'];

                if ($user['user_type'] === 'admin') {

                    header("Location: admin_dashboard.php");
                    exit();

                } elseif ($user['user_type'] === 'owner') {

                    header("Location: managecabins.php");
                    exit();

                } elseif ($user['user_type'] === 'attraction_owner') {

                    header("Location: manageattractions.php");
                    exit();

                } else {

                    header("Location: home.php");
                    exit();
                }

            } else {

                $message = t('wrong_password');

            }

        } else {

            $message = t('user_not_found');

        }

        $stmt->close();
    }
}

?>

<!DOCTYPE html>

<html
    lang="<?= current_language(); ?>"
    dir="<?= is_rtl() ? 'rtl' : 'ltr'; ?>"
>

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title><?= t('login'); ?></title>

    <link
        rel="stylesheet"
        href="style.css?v=<?= time(); ?>"
    >

</head>

<body>

<?php include "navbar.php"; ?>

<div class="container">

    <div class="card">

        <h2><?= t('login'); ?></h2>

        <?php if ($message !== ""): ?>

            <div class="msg">

                <?= htmlspecialchars($message); ?>

            </div>

        <?php endif; ?>

        <form method="POST" autocomplete="off">

            <input
                type="email"
                name="email"
                placeholder="<?= t('email'); ?>"
                value="<?= htmlspecialchars($_POST['email'] ?? ''); ?>"
                required
            >

            <input
                type="password"
                name="password"
                placeholder="<?= t('password'); ?>"
                required
            >

            <button
                type="submit"
                name="login"
            >
                <?= t('login'); ?>
            </button>

        </form>

    </div>

</div>

</body>

</html>