<?php
session_start();
include "connection.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if ($_SESSION['user_type'] != 'owner') {
    die("Only owner allowed");
}

if (!isset($_GET['id'])) {
    die("No cabin selected");
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
    die("Cabin not found");
}

/* שליפת שירותים */
$services = $conn->query("SELECT * FROM services");

/* שירותים של הצימר */
$cabin_services = [];
$res = $conn->query("SELECT service_id FROM cabin_services WHERE cabin_id=$cabin_id");
while($row = $res->fetch_assoc()) {
    $cabin_services[] = $row['service_id'];
}

/* עדכון */
if (isset($_POST['update'])) {

    $name = $_POST['name'] ?? "";
    $location = $_POST['location'] ?? "";
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
        $conn->query("DELETE FROM cabin_services WHERE cabin_id=$cabin_id");

        /* שירותים קיימים */
        if (!empty($_POST['services'])) {
            foreach ($_POST['services'] as $srv) {
                $srv = intval($srv);
                $conn->query("INSERT INTO cabin_services (cabin_id, service_id) VALUES ($cabin_id, $srv)");
            }
        }

        /* ===== שירות חדש כולל תיאור ===== */
        $new_name = trim($_POST['new_service_name'] ?? "");
        $new_price = $_POST['new_service_price'] ?? "";
        $new_description = trim($_POST['new_service_description'] ?? "");

        if ($new_name !== "" && is_numeric($new_price)) {

            /* אם אין תיאור */
            if ($new_description == "") {
                $new_description = "No description available";
            }

            /* בדיקה אם קיים */
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

            /* חיבור לצימר */
            $conn->query("
                INSERT INTO cabin_services (cabin_id, service_id)
                VALUES ($cabin_id, $new_service_id)
            ");
        }

        /* העלאת תמונה */
        if (!empty($_FILES['image']['name'])) {

            $target = "uploads/" . time() . "_" . $_FILES['image']['name'];
            move_uploaded_file($_FILES['image']['tmp_name'], $target);

            $conn->query("
                INSERT INTO cabin_images (cabin_id, image_path)
                VALUES ($cabin_id, '$target')
            ");
        }

        $message = "Updated successfully 🎉";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit Cabin</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
</head>

<body>

<?php include "navbar.php"; ?>

<div class="edit-page">

    <div class="edit-container">

        <div class="edit-header">
            <h1>Edit Cabin</h1>
            <p>Update your cabin details, services and images</p>
        </div>

        <div class="edit-card">

            <?php if($message != "") { ?>
                <div class="success"><?= $message ?></div>
            <?php } ?>

            <form method="POST" enctype="multipart/form-data">

                <h3>Basic Info</h3>

                <input type="text" name="name" value="<?= htmlspecialchars($cabin['name']) ?>" placeholder="Cabin Name">
                <input type="text" name="location" value="<?= htmlspecialchars($cabin['location']) ?>" placeholder="Location">

                <div class="row">
                    <input type="number" name="price" value="<?= $cabin['price_per_night'] ?>" placeholder="Price">
                    <input type="number" name="guests" value="<?= $cabin['max_guests'] ?>" placeholder="Guests">
                </div>

                <h3>Services</h3>

                <div class="services">

                    <?php while($srv = $services->fetch_assoc()) { ?>

                        <label>
                            <input type="checkbox" name="services[]" value="<?= $srv['service_id'] ?>"
                            <?= in_array($srv['service_id'], $cabin_services) ? "checked" : "" ?>>

                            <?= htmlspecialchars($srv['service_name']) ?>

                            <br>
                            <small style="color:#6b7280;">
                                <?= htmlspecialchars($srv['description']) ?>
                            </small>

                        </label>

                    <?php } ?>

                </div>

                <!-- ===== שירות חדש ===== -->
                <h3>Add New Service</h3>

                <div class="row">
                    <input type="text" name="new_service_name" placeholder="Service name">
                    <input type="number" name="new_service_price" placeholder="Price ($)">
                </div>

                <input type="text" name="new_service_description" placeholder="Description">

                <h3>Upload Image</h3>

                <input type="file" name="image">

                <button name="update">💾 Save Changes</button>

            </form>

        </div>

    </div>

</div>

</body>
</html>