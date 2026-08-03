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

if (isset($_POST['upload_image'])) {

    if (
        !isset($_FILES['profile_image']) ||
        $_FILES['profile_image']['error'] === UPLOAD_ERR_NO_FILE
    ) {
        $message = t('profile_choose_image_error');
        $message_type = "error";

    } else {

        $file = $_FILES['profile_image'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $message = t('profile_upload_problem');
            $message_type = "error";

        } else {

            $allowed_types = [
                'image/jpeg',
                'image/png',
                'image/webp'
            ];

            $file_type = mime_content_type($file['tmp_name']);

            if (!in_array($file_type, $allowed_types, true)) {
                $message = t('profile_invalid_image_type');
                $message_type = "error";

            } elseif ($file['size'] > 5 * 1024 * 1024) {
                $message = t('profile_image_too_large');
                $message_type = "error";

            } else {

                $upload_directory = "uploads/profile/";

                if (
                    !is_dir($upload_directory) &&
                    !mkdir($upload_directory, 0777, true)
                ) {
                    $message = t('profile_upload_folder_error');
                    $message_type = "error";

                } else {

                    $extension = match ($file_type) {
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/webp' => 'webp',
                        default => 'jpg'
                    };

                    $file_name =
                        "user_" .
                        $user_id .
                        "_" .
                        time() .
                        "." .
                        $extension;

                    $file_path = $upload_directory . $file_name;

                    if (move_uploaded_file($file['tmp_name'], $file_path)) {

                        $update = $conn->prepare("
                            UPDATE users
                            SET profile_image = ?
                            WHERE id = ?
                        ");

                        if (!$update) {
                            $message = t('profile_database_error');
                            $message_type = "error";

                        } else {

                            $update->bind_param(
                                "si",
                                $file_path,
                                $user_id
                            );

                            if ($update->execute()) {
                                $message = t('profile_image_updated');
                                $message_type = "success";
                            } else {
                                $message = t('profile_image_save_error');
                                $message_type = "error";
                            }

                            $update->close();
                        }

                    } else {
                        $message = t('profile_upload_failed');
                        $message_type = "error";
                    }
                }
            }
        }
    }
}

$stmt = $conn->prepare("
    SELECT
        first_name,
        last_name,
        email,
        phone,
        user_type,
        profile_image
    FROM users
    WHERE id = ?
    LIMIT 1
");

if (!$stmt) {
    die(t('profile_database_error'));
}

$stmt->bind_param("i", $user_id);
$stmt->execute();

$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    $stmt->close();
    session_destroy();
    header("Location: login.php");
    exit();
}

function profileRoleLabel(string $role): string
{
    $key = 'profile_role_' . strtolower($role);
    return t($key);
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
        <?= htmlspecialchars(t('profile_page_title')) ?>
    </title>

    <link
        rel="stylesheet"
        href="style.css?v=<?= time() ?>"
    >

</head>

<body>

<?php include "navbar.php"; ?>

<main class="profile-page">

    <section class="profile-card">

        <?php if ($message !== ""): ?>

            <div
                class="profile-message <?= htmlspecialchars($message_type) ?>"
            >
                <?= htmlspecialchars($message) ?>
            </div>

        <?php endif; ?>

        <div class="profile-image-area">

            <?php if (!empty($user['profile_image'])): ?>

                <img
                    src="<?= htmlspecialchars($user['profile_image']) ?>"
                    alt="<?= htmlspecialchars(t('profile_image_alt')) ?>"
                    class="profile-image"
                    id="profilePreview"
                >

            <?php else: ?>

                <div
                    class="profile-avatar"
                    id="profileAvatar"
                >
                    <?= htmlspecialchars(
                        strtoupper(
                            mb_substr($user['first_name'], 0, 1)
                        )
                    ) ?>
                </div>

                <img
                    src=""
                    alt="<?= htmlspecialchars(t('profile_selected_image_alt')) ?>"
                    class="profile-image profile-preview-hidden"
                    id="profilePreview"
                >

            <?php endif; ?>

            <form
                method="POST"
                enctype="multipart/form-data"
                class="profile-image-form"
                id="profileImageForm"
            >

                <input
                    type="file"
                    name="profile_image"
                    id="profile_image"
                    class="profile-file-input"
                    accept=".jpg,.jpeg,.png,.webp"
                >

                <label
                    for="profile_image"
                    class="profile-upload-label"
                >
                    <span id="profileUploadText">
                        <?= htmlspecialchars(t('profile_choose_image')) ?>
                    </span>
                </label>

                <div
                    class="profile-selected-file"
                    id="profileSelectedFile"
                    data-empty-text="<?= htmlspecialchars(t('profile_no_image_selected')) ?>"
                    data-change-text="<?= htmlspecialchars(t('profile_change_selected_image')) ?>"
                    data-error-text="<?= htmlspecialchars(t('profile_choose_image_error')) ?>"
                >
                    <?= htmlspecialchars(t('profile_no_image_selected')) ?>
                </div>

                <small class="profile-file-note">
                    <?= htmlspecialchars(t('profile_file_note')) ?>
                </small>

                <button
                    type="submit"
                    name="upload_image"
                    class="profile-upload-button"
                >
                    <?= htmlspecialchars(t('profile_upload_image')) ?>
                </button>

            </form>

        </div>

        <div class="profile-details">

            <h1>
                <?= htmlspecialchars(
                    $user['first_name'] .
                    " " .
                    $user['last_name']
                ) ?>
            </h1>

            <div class="profile-info-row">
                <span><?= htmlspecialchars(t('first_name')) ?></span>
                <strong><?= htmlspecialchars($user['first_name']) ?></strong>
            </div>

            <div class="profile-info-row">
                <span><?= htmlspecialchars(t('last_name')) ?></span>
                <strong><?= htmlspecialchars($user['last_name']) ?></strong>
            </div>

            <div class="profile-info-row">
                <span><?= htmlspecialchars(t('email')) ?></span>
                <strong><?= htmlspecialchars($user['email']) ?></strong>
            </div>

            <div class="profile-info-row">
                <span><?= htmlspecialchars(t('phone')) ?></span>

                <strong>
                    <?= htmlspecialchars(
                        !empty($user['phone'])
                            ? $user['phone']
                            : t('profile_not_provided')
                    ) ?>
                </strong>
            </div>

            <div class="profile-info-row">
                <span><?= htmlspecialchars(t('profile_account_type')) ?></span>

                <strong>
                    <?= htmlspecialchars(
                        profileRoleLabel($user['user_type'])
                    ) ?>
                </strong>
            </div>

            <div class="profile-buttons">

                <a href="edit_profile.php">
                    <?= htmlspecialchars(t('edit_profile_title')) ?>
                </a>

                <a href="change_password.php">
                    <?= htmlspecialchars(t('change_password_title')) ?>
                </a>

            </div>

        </div>

    </section>

</main>

<?php include "footer.php"; ?>

<script>

document.addEventListener("DOMContentLoaded", function () {

    const form = document.getElementById("profileImageForm");
    const input = document.getElementById("profile_image");
    const selectedFile = document.getElementById("profileSelectedFile");
    const uploadText = document.getElementById("profileUploadText");
    const preview = document.getElementById("profilePreview");
    const avatar = document.getElementById("profileAvatar");

    if (!form || !input || !selectedFile || !uploadText) {
        return;
    }

    const emptyText = selectedFile.dataset.emptyText;
    const changeText = selectedFile.dataset.changeText;
    const errorText = selectedFile.dataset.errorText;

    input.addEventListener("change", function () {

        selectedFile.classList.remove("error");

        if (this.files.length === 0) {
            selectedFile.textContent = emptyText;
            uploadText.textContent = "<?= addslashes(t('profile_choose_image')) ?>";
            return;
        }

        const file = this.files[0];

        selectedFile.textContent = "✓ " + file.name;
        uploadText.textContent = changeText;

        if (preview && file.type.startsWith("image/")) {

            const reader = new FileReader();

            reader.addEventListener("load", function (event) {

                preview.src = event.target.result;
                preview.classList.remove("profile-preview-hidden");

                if (avatar) {
                    avatar.style.display = "none";
                }

            });

            reader.readAsDataURL(file);
        }

    });

    form.addEventListener("submit", function (event) {

        if (input.files.length === 0) {
            event.preventDefault();

            selectedFile.textContent = errorText;
            selectedFile.classList.add("error");
        }

    });

});

</script>

</body>
</html>

<?php

$stmt->close();
$conn->close();

?>
