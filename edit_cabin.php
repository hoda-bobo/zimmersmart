<?php
session_start();
include "connection.php";
include "lead_helper.php";
include "language.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if ($_SESSION['user_type'] != 'owner') {
    die(t('access_denied'));
}

if (!isset($_GET['id'])) {
    die(t('no_cabin_selected'));
}

$cabin_id = intval($_GET['id']);
$owner_id = $_SESSION['user_id'];

$message = "";

/* שליפת צימר */
$stmt = $conn->prepare("
    SELECT * FROM cabins
    WHERE id=? AND owner_id=?
");
$stmt->bind_param("ii", $cabin_id, $owner_id);
$stmt->execute();
$cabin = $stmt->get_result()->fetch_assoc();

if (!$cabin) {
    die(t('cabin_not_found'));
}

/* שליפת שירותים */
$services = $conn->query("SELECT * FROM services");

/* שירותים של הצימר */
$cabin_services = [];
$res = $conn->query("SELECT service_id FROM cabin_services WHERE cabin_id=$cabin_id");
while ($row = $res->fetch_assoc()) {
    $cabin_services[] = $row['service_id'];
}

/* עדכון */
if (isset($_POST['update'])) {

    $name = trim($_POST['name'] ?? "");
    $location = trim($_POST['location'] ?? "");
    $price = $_POST['price'] ?? "";
    $guests = $_POST['guests'] ?? "";

    /* עדכון פרטים */
    $update = $conn->prepare("
        UPDATE cabins
        SET name=?, location=?, price_per_night=?, max_guests=?
        WHERE id=? AND owner_id=?
    ");
    $update->bind_param("ssdiii", $name, $location, $price, $guests, $cabin_id, $owner_id);

    if ($update->execute()) {

        /* ניקוי שירותים */
        $delete_services = $conn->prepare("DELETE FROM cabin_services WHERE cabin_id=?");
        $delete_services->bind_param("i", $cabin_id);
        $delete_services->execute();

        /* שירותים קיימים */
        if (!empty($_POST['services'])) {
            $insert_cabin_service = $conn->prepare("
                INSERT INTO cabin_services (cabin_id, service_id)
                VALUES (?, ?)
            ");

            foreach ($_POST['services'] as $srv) {
                $srv = intval($srv);
                $insert_cabin_service->bind_param("ii", $cabin_id, $srv);
                $insert_cabin_service->execute();
            }
        }

        /* שירות חדש כולל תיאור */
        $new_name = trim($_POST['new_service_name'] ?? "");
        $new_price = $_POST['new_service_price'] ?? "";
        $new_description = trim($_POST['new_service_description'] ?? "");

        if ($new_name !== "" && is_numeric($new_price)) {

            if ($new_description === "") {
                $new_description = t('no_description_available');
            }

            $check = $conn->prepare("SELECT service_id FROM services WHERE service_name=?");
            $check->bind_param("s", $new_name);
            $check->execute();
            $result = $check->get_result();

            if ($row = $result->fetch_assoc()) {
                $new_service_id = $row['service_id'];
            } else {
                $insert_service = $conn->prepare("
                    INSERT INTO services (service_name, description, price)
                    VALUES (?, ?, ?)
                ");
                $insert_service->bind_param("ssd", $new_name, $new_description, $new_price);
                $insert_service->execute();

                $new_service_id = $conn->insert_id;
            }

            $connect_service = $conn->prepare("
                INSERT INTO cabin_services (cabin_id, service_id)
                VALUES (?, ?)
            ");
            $connect_service->bind_param("ii", $cabin_id, $new_service_id);
            $connect_service->execute();
        }

        /* העלאת תמונה */
        if (!empty($_FILES['image']['name'])) {

            $original_name = basename($_FILES['image']['name']);
            $safe_name = preg_replace('/[^A-Za-z0-9._-]/', '_', $original_name);
            $target = "uploads/" . time() . "_" . $safe_name;

            if (move_uploaded_file($_FILES['image']['tmp_name'], $target)) {
                $insert_image = $conn->prepare("
                    INSERT INTO cabin_images (cabin_id, image_path)
                    VALUES (?, ?)
                ");
                $insert_image->bind_param("is", $cabin_id, $target);
                $insert_image->execute();
            }
        }

        $message = t('cabin_updated_successfully');
    } else {
        $message = t('failed_to_update_cabin');
    }
}
?>

<!DOCTYPE html>
<html lang="<?= ($_SESSION['lang'] ?? 'en') === 'he' ? 'he' : 'en' ?>"
      dir="<?= ($_SESSION['lang'] ?? 'en') === 'he' ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="UTF-8">
    <title><?= t('edit_cabin') ?></title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
</head>

<body>

<?php include "navbar.php"; ?>

<div class="edit-page">

    <div class="edit-container">

        <div class="edit-header">
            <h1><?= t('edit_cabin') ?></h1>
            <p><?= t('edit_cabin_description') ?></p>
        </div>

        <div class="edit-card">

            <?php if ($message !== "") { ?>
                <div class="success"><?= htmlspecialchars($message) ?></div>
            <?php } ?>

            <form method="POST" enctype="multipart/form-data">

                <h3><?= t('basic_information') ?></h3>

                <input
                    type="text"
                    name="name"
                    value="<?= htmlspecialchars($cabin['name']) ?>"
                    placeholder="<?= t('cabin_name') ?>"
                    required
                >

                <input
                    type="text"
                    name="location"
                    value="<?= htmlspecialchars($cabin['location']) ?>"
                    placeholder="<?= t('location') ?>"
                    required
                >

                <div class="row">
                    <input
                        type="number"
                        step="0.01"
                        min="0"
                        name="price"
                        value="<?= htmlspecialchars($cabin['price_per_night']) ?>"
                        placeholder="<?= t('price_per_night') ?>"
                        required
                    >

                    <input
                        type="number"
                        min="1"
                        name="guests"
                        value="<?= htmlspecialchars($cabin['max_guests']) ?>"
                        placeholder="<?= t('guests') ?>"
                        required
                    >
                </div>

                <h3><?= t('services') ?></h3>

                <div class="services">

                    <?php while ($srv = $services->fetch_assoc()) { ?>

                        <label>
                            <input
                                type="checkbox"
                                name="services[]"
                                value="<?= $srv['service_id'] ?>"
                                <?= in_array($srv['service_id'], $cabin_services) ? "checked" : "" ?>
                            >

                            <?= htmlspecialchars($srv['service_name']) ?>

                            <br>
                            <small style="color:#6b7280;">
                                <?= htmlspecialchars($srv['description']) ?>
                            </small>
                        </label>

                    <?php } ?>

                </div>

                <h3><?= t('add_new_service') ?></h3>

                <div class="row">
                    <input
                        type="text"
                        name="new_service_name"
                        placeholder="<?= t('service_name') ?>"
                    >

                    <input
                        type="number"
                        step="0.01"
                        min="0"
                        name="new_service_price"
                        placeholder="<?= t('service_price') ?>"
                    >
                </div>

                <input
                    type="text"
                    name="new_service_description"
                    placeholder="<?= t('service_description') ?>"
                >

                <h3><?= t('upload_image') ?></h3>

                <input type="file" name="image" accept="image/*">

                <button type="submit" name="update">
                    💾 <?= t('save_changes') ?>
                </button>

            </form>

        </div>

    </div>

</div>

</body>
</html>