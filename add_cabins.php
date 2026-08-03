<?php
session_start();

include "connection.php";
include "language.php";
include "lead_helper.php";

$message = "";
$message_type = "";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (($_SESSION['user_type'] ?? '') !== 'owner') {
    die(t('access_denied'));
}

if (isset($_POST['add'])) {

    $owner_id = (int)$_SESSION['user_id'];
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $guests = (int)($_POST['guests'] ?? 0);

    if ($name === '' || $location === '' || $price <= 0 || $guests <= 0) {
        $message = t('please_fill_required_fields');
        $message_type = "error";
    } else {

        $stmt = $conn->prepare("
            INSERT INTO cabins
                (owner_id, name, description, location, price_per_night, max_guests)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "isssdi",
            $owner_id,
            $name,
            $description,
            $location,
            $price,
            $guests
        );

        if ($stmt->execute()) {

            $cabin_id = $stmt->insert_id;

            $folder = "uploads/";

            if (!is_dir($folder)) {
                mkdir($folder, 0777, true);
            }

            if (
                isset($_FILES['images']) &&
                is_array($_FILES['images']['name'])
            ) {
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'webp'];

                for ($i = 0; $i < count($_FILES['images']['name']); $i++) {

                    $original_name = $_FILES['images']['name'][$i] ?? '';
                    $tmp_name = $_FILES['images']['tmp_name'][$i] ?? '';
                    $upload_error = $_FILES['images']['error'][$i] ?? UPLOAD_ERR_NO_FILE;

                    if (
                        $original_name === '' ||
                        $upload_error !== UPLOAD_ERR_OK ||
                        !is_uploaded_file($tmp_name)
                    ) {
                        continue;
                    }

                    $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

                    if (!in_array($extension, $allowed_extensions, true)) {
                        continue;
                    }

                    $new_name = uniqid('cabin_', true) . '.' . $extension;
                    $path = $folder . $new_name;

                    if (move_uploaded_file($tmp_name, $path)) {

                        $img = $conn->prepare("
                            INSERT INTO cabin_images (cabin_id, image_path)
                            VALUES (?, ?)
                        ");

                        $img->bind_param("is", $cabin_id, $path);
                        $img->execute();
                        $img->close();
                    }
                }
            }

            $message = t('cabin_added_successfully');
            $message_type = "success";

            $_POST = [];

        } else {
            $message = t('cabin_add_error');
            $message_type = "error";
        }

        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="<?= ($_SESSION['lang'] ?? 'en') === 'he' ? 'he' : 'en' ?>"
      dir="<?= ($_SESSION['lang'] ?? 'en') === 'he' ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= t('add_cabin') ?></title>

    <link rel="stylesheet" href="style.css?v=<?= time() ?>">
</head>

<body>

<?php include "navbar.php"; ?>

<div class="add-wrapper">

    <div class="cabin-card add-card">

        <div class="cabin-info">

            <h2><?= t('add_new_cabin') ?></h2>

            <p><?= t('create_your_vacation_cabin') ?></p>

            <?php if ($message !== "") { ?>

                <div class="<?= $message_type === 'success' ? 'success-msg' : 'error-msg' ?>">
                    <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                </div>

            <?php } ?>

            <form method="POST" enctype="multipart/form-data" class="form-grid">

                <div class="form-row">

                    <div class="form-group">

                        <label for="name">
                            <?= t('cabin_name') ?>
                        </label>

                        <input
                            type="text"
                            id="name"
                            name="name"
                            required
                            value="<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                        >

                    </div>

                    <div class="form-group">

                        <label for="location">
                            <?= t('location') ?>
                        </label>

                        <input
                            type="text"
                            id="location"
                            name="location"
                            required
                            value="<?= htmlspecialchars($_POST['location'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                        >

                    </div>

                </div>

                <div class="form-group">

                    <label for="description">
                        <?= t('description') ?>
                    </label>

                    <textarea
                        id="description"
                        name="description"
                    ><?= htmlspecialchars($_POST['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>

                </div>

                <div class="form-row">

                    <div class="form-group">

                        <label for="price">
                            <?= t('price_per_night') ?>
                        </label>

                        <input
                            type="number"
                            id="price"
                            name="price"
                            min="1"
                            step="0.01"
                            required
                            value="<?= htmlspecialchars($_POST['price'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                        >

                    </div>

                    <div class="form-group">

                        <label for="guests">
                            <?= t('max_guests') ?>
                        </label>

                        <input
                            type="number"
                            id="guests"
                            name="guests"
                            min="1"
                            required
                            value="<?= htmlspecialchars($_POST['guests'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                        >

                    </div>

                </div>

                <div class="form-group">

                    <label for="images">
                        <?= t('cabin_images') ?>
                    </label>

                    <input
                        type="file"
                        id="images"
                        name="images[]"
                        accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                        multiple
                    >

                    <small>
                        <?= t('allowed_image_types') ?>
                    </small>

                </div>

                <button
                    type="submit"
                    class="hero-btn add-btn"
                    name="add"
                >
                    <?= t('add_cabin') ?>
                </button>

            </form>

        </div>

    </div>

</div>

<?php include "footer.php"; ?>

</body>
</html>