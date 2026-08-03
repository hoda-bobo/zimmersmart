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

/*
|--------------------------------------------------------------------------
| Get current user
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        first_name,
        last_name,
        email,
        phone
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

if (!$user) {
    session_destroy();

    header("Location: login.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| Update profile
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['update'])
) {

    $first_name = trim(
        $_POST['first_name'] ?? ''
    );

    $last_name = trim(
        $_POST['last_name'] ?? ''
    );

    $email = trim(
        $_POST['email'] ?? ''
    );

    $phone = trim(
        $_POST['phone'] ?? ''
    );

    if (
        $first_name === '' ||
        $last_name === '' ||
        $email === ''
    ) {

        $message = t('edit_profile_required_fields');
        $message_type = "error";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = t('edit_profile_invalid_email');
        $message_type = "error";

    } elseif (mb_strlen($first_name) < 2) {

        $message = t('edit_profile_first_name_short');
        $message_type = "error";

    } elseif (mb_strlen($last_name) < 2) {

        $message = t('edit_profile_last_name_short');
        $message_type = "error";

    } else {

        /*
        |--------------------------------------------------------------------------
        | Check if email belongs to another user
        |--------------------------------------------------------------------------
        */

        $email_check = $conn->prepare("
            SELECT id
            FROM users
            WHERE email = ?
              AND id != ?
            LIMIT 1
        ");

        if (!$email_check) {
            die("Database error: " . $conn->error);
        }

        $email_check->bind_param(
            "si",
            $email,
            $user_id
        );

        $email_check->execute();

        $existing_email = $email_check
            ->get_result()
            ->fetch_assoc();

        $email_check->close();

        if ($existing_email) {

            $message = t('edit_profile_email_exists');
            $message_type = "error";

        } else {

            $update = $conn->prepare("
                UPDATE users
                SET
                    first_name = ?,
                    last_name = ?,
                    email = ?,
                    phone = ?
                WHERE id = ?
            ");

            if (!$update) {
                die("Database error: " . $conn->error);
            }

            $update->bind_param(
                "ssssi",
                $first_name,
                $last_name,
                $email,
                $phone,
                $user_id
            );

            if ($update->execute()) {

                $_SESSION['first_name'] = $first_name;
                $_SESSION['last_name'] = $last_name;
                $_SESSION['name'] =
                    $first_name . " " . $last_name;

                $message = t('edit_profile_success');
                $message_type = "success";

                /*
                |--------------------------------------------------------------------------
                | Refresh user information
                |--------------------------------------------------------------------------
                */

                $user['first_name'] = $first_name;
                $user['last_name'] = $last_name;
                $user['email'] = $email;
                $user['phone'] = $phone;

            } else {

                $message = t('edit_profile_error');
                $message_type = "error";
            }

            $update->close();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Keep entered values after validation error
    |--------------------------------------------------------------------------
    */

    if ($message_type === "error") {
        $user['first_name'] = $first_name;
        $user['last_name'] = $last_name;
        $user['email'] = $email;
        $user['phone'] = $phone;
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
        <?= htmlspecialchars(
            t('edit_profile_page_title')
        ) ?>
    </title>

    <link
        rel="stylesheet"
        href="style.css?v=<?= time() ?>"
    >

</head>

<body>

<?php include "navbar.php"; ?>

<main class="edit-profile-page">

    <section class="edit-profile-card">

        <div class="edit-profile-heading">

            <h1>
                <?= htmlspecialchars(
                    t('edit_profile_title')
                ) ?>
            </h1>

            <p>
                <?= htmlspecialchars(
                    t('edit_profile_description')
                ) ?>
            </p>

        </div>

        <?php if ($message !== ""): ?>

            <div
                class="edit-profile-message
                <?= htmlspecialchars($message_type) ?>"
            >
                <?= htmlspecialchars($message) ?>
            </div>

        <?php endif; ?>

        <form
            method="POST"
            class="edit-profile-form"
            novalidate
        >

            <div class="edit-profile-row">

                <div class="edit-profile-field">

                    <label for="first_name">

                        <?= htmlspecialchars(
                            t('first_name')
                        ) ?>

                        <span class="required-mark">
                            *
                        </span>

                    </label>

                    <small class="required-field-text">
                        <?= htmlspecialchars(
                            t('required_field')
                        ) ?>
                    </small>

                    <input
                        type="text"
                        id="first_name"
                        name="first_name"
                        value="<?= htmlspecialchars(
                            $user['first_name']
                        ) ?>"
                        placeholder="<?= htmlspecialchars(
                            t('first_name_placeholder')
                        ) ?>"
                        maxlength="100"
                        autocomplete="given-name"
                    >

                </div>

                <div class="edit-profile-field">

                    <label for="last_name">

                        <?= htmlspecialchars(
                            t('last_name')
                        ) ?>

                        <span class="required-mark">
                            *
                        </span>

                    </label>

                    <small class="required-field-text">
                        <?= htmlspecialchars(
                            t('required_field')
                        ) ?>
                    </small>

                    <input
                        type="text"
                        id="last_name"
                        name="last_name"
                        value="<?= htmlspecialchars(
                            $user['last_name']
                        ) ?>"
                        placeholder="<?= htmlspecialchars(
                            t('last_name_placeholder')
                        ) ?>"
                        maxlength="100"
                        autocomplete="family-name"
                    >

                </div>

            </div>

            <div class="edit-profile-field">

                <label for="email">

                    <?= htmlspecialchars(
                        t('email')
                    ) ?>

                    <span class="required-mark">
                        *
                    </span>

                </label>

                <small class="required-field-text">
                    <?= htmlspecialchars(
                        t('required_field')
                    ) ?>
                </small>

                <input
                    type="email"
                    id="email"
                    name="email"
                    value="<?= htmlspecialchars(
                        $user['email']
                    ) ?>"
                    placeholder="<?= htmlspecialchars(
                        t('email_placeholder')
                    ) ?>"
                    maxlength="190"
                    autocomplete="email"
                >

            </div>

            <div class="edit-profile-field">

                <label for="phone">
                    <?= htmlspecialchars(
                        t('phone')
                    ) ?>
                </label>

                <small class="optional-field-text">
                    <?= htmlspecialchars(
                        t('optional_field')
                    ) ?>
                </small>

                <input
                    type="tel"
                    id="phone"
                    name="phone"
                    value="<?= htmlspecialchars(
                        $user['phone'] ?? ''
                    ) ?>"
                    placeholder="<?= htmlspecialchars(
                        t('phone_placeholder')
                    ) ?>"
                    maxlength="30"
                    autocomplete="tel"
                >

            </div>

            <button
                type="submit"
                name="update"
                class="edit-profile-submit"
            >
                <?= htmlspecialchars(
                    t('save_changes')
                ) ?>
            </button>

            <a
                href="profile.php"
                class="edit-profile-back"
            >
                <?= htmlspecialchars(
                    t('back_to_profile')
                ) ?>
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