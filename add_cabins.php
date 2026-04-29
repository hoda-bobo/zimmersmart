<?php
session_start();
include "connection.php";

$message = "";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (isset($_POST['add'])) {

    $owner_id = $_SESSION['user_id'];
    $name = $_POST['name'];
    $description = $_POST['description'];
    $location = $_POST['location'];
    $price = $_POST['price'];
    $guests = $_POST['guests'];

    $stmt = $conn->prepare("
        INSERT INTO cabins (owner_id, name, description, location, price_per_night, max_guests)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param("isssdi", $owner_id, $name, $description, $location, $price, $guests);

    if ($stmt->execute()) {

        $cabin_id = $stmt->insert_id;

        $folder = "uploads/";
        if (!is_dir($folder)) {
            mkdir($folder);
        }

        for ($i = 0; $i < count($_FILES['images']['name']); $i++) {

            $file = $_FILES['images']['name'][$i];
            $tmp = $_FILES['images']['tmp_name'][$i];

            if ($file != "") {

                $new_name = time() . "_" . $file;
                $path = $folder . $new_name;

                if (move_uploaded_file($tmp, $path)) {

                    $img = $conn->prepare("
                        INSERT INTO cabin_images (cabin_id, image_path)
                        VALUES (?, ?)
                    ");
                    $img->bind_param("is", $cabin_id, $path);
                    $img->execute();
                }
            }
        }

        $message = "Cabin added successfully!";
    } else {
        $message = "Error!";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Add Cabin</title>

    <!-- 🔥 חיבור CSS -->
    <link rel="stylesheet" href="style.css">
</head>

<body>

<?php include "navbar.php"; ?>

<div class="add-wrapper">

    <div class="cabin-card add-card">

        <div class="cabin-info">

            <h2>Add New Cabin</h2>
            <p>Create your vacation cabin</p>

            <?php if (!empty($message)) { ?>
                <div class="success-msg"><?= $message ?></div>
            <?php } ?>

            <form method="POST" enctype="multipart/form-data" class="form-grid">

                <div class="form-row">
                    <div class="form-group">
                        <label>Name</label>
                        <input type="text" name="name" required>
                    </div>

                    <div class="form-group">
                        <label>Location</label>
                        <input type="text" name="location" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description"></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Price</label>
                        <input type="number" name="price" required>
                    </div>

                    <div class="form-group">
                        <label>Guests</label>
                        <input type="number" name="guests" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Images</label>
                    <input type="file" name="images[]" multiple>
                </div>

                <button class="hero-btn add-btn" name="add">
                    Add Cabin
                </button>

            </form>

        </div>

    </div>

</div>

</body>
</html>