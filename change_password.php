<?php

session_start();

include "connection.php";
include "lead_helper.php";
include "language.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$message = "";
$message_type = "";

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['change'])
) {
    $current_password = trim($_POST['current_password'] ?? "");
    $new_password = trim($_POST['new_password'] ?? "");

    if ($current_password === "" || $new_password === "") {
        $message = t('change_password_required_fields');
        $message_type = "error";

    } elseif (mb_strlen($new_password) < 6) {
        $message = t('change_password_too_short');
        $message_type = "error";

    } else {
        $stmt = $conn->prepare("
            SELECT password
            FROM users
            WHERE id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            die("Database error: " . $conn->error);
        }

        $stmt->bind_param("i", $user_id);
        $stmt->execute();

        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (
            $user &&
            password_verify($current_password, $user['password'])
        ) {
            $new_hashed = password_hash(
                $new_password,
                PASSWORD_DEFAULT
            );

            $update = $conn->prepare("
                UPDATE users
                SET password = ?
                WHERE id = ?
            ");

            if (!$update) {
                die("Database error: " . $conn->error);
            }

            $update->bind_param(
                "si",
                $new_hashed,
                $user_id
            );

            if ($update->execute()) {
                $message = t('change_password_success');
                $message_type = "success";
            } else {
                $message = t('change_password_error');
                $message_type = "error";
            }

            $update->close();

        } else {
            $message = t('change_password_incorrect_current');
            $message_type = "error";
        }
    }
}

?>

<!DOCTYPE html>

<html
    lang="<?= current_language() ?>"
    dir="<?= is_rtl() ? 'rtl' : 'ltr' ?>"
>

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        <?= htmlspecialchars(t('change_password_page_title')) ?>
    </title>

    <link
        rel="stylesheet"
        href="style.css?v=<?= time() ?>"
    >

</head>

<body>

<?php include "navbar.php"; ?>

<main class="change-password-page">

    <section class="change-password-card">

        <div class="change-password-heading">

            <h1>
                <?= htmlspecialchars(t('change_password_title')) ?>
            </h1>

            <p>
                <?= htmlspecialchars(t('change_password_description')) ?>
            </p>

        </div>

        <?php if ($message !== ""): ?>

            <div
                class="change-password-message <?= htmlspecialchars($message_type) ?>"
            >
                <?= htmlspecialchars($message) ?>
            </div>

        <?php endif; ?>

        <form
            method="POST"
            class="change-password-form"
            novalidate
        >

            <div class="change-password-field">

                <label for="current_password">
                    <?= htmlspecialchars(t('current_password')) ?>
                    <span class="required-mark">*</span>
                </label>

                <small class="required-field-text">
                    <?= htmlspecialchars(t('required_field')) ?>
                </small>

                <input
                    type="password"
                    id="current_password"
                    name="current_password"
                    placeholder="<?= htmlspecialchars(t('current_password_placeholder')) ?>"
                    autocomplete="current-password"
                >

            </div>

            <div class="change-password-field">

                <label for="new_password">
                    <?= htmlspecialchars(t('new_password')) ?>
                    <span class="required-mark">*</span>
                </label>

                <small class="required-field-text">
                    <?= htmlspecialchars(t('password_minimum_six')) ?>
                </small>

                <input
                    type="password"
                    id="new_password"
                    name="new_password"
                    placeholder="<?= htmlspecialchars(t('new_password_placeholder')) ?>"
                    minlength="6"
                    autocomplete="new-password"
                >

            </div>

            <button
                type="submit"
                name="change"
                class="change-password-submit"
            >
                <?= htmlspecialchars(t('update_password')) ?>
            </button>

            <a
                href="profile.php"
                class="change-password-back"
            >
                <?= htmlspecialchars(t('back_to_profile')) ?>
            </a>

        </form>

    </section>

</main>

<?php include "footer.php"; ?>

</body>
</html>

<?php

$conn->close();

?>
