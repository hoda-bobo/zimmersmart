<?php
session_start();
include "connection.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$message = "";

if (isset($_POST['add'])) {

    $owner_id = $_SESSION['user_id'];
    $name = $_POST['name'];
    $description = $_POST['description'];
    $location = $_POST['location'];
    $price = $_POST['price'];
    $guests = $_POST['guests'];

    /* הוספת צימר */
    $stmt = $conn->prepare("
        INSERT INTO cabins (owner_id, name, description, location, price_per_night, max_guests)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("isssdi", $owner_id, $name, $description, $location, $price, $guests);

    if ($stmt->execute()) {

        $cabin_id = $stmt->insert_id;

        /* העלאת תמונות */
        $upload_folder = "uploads/";

        if (!is_dir($upload_folder)) {
            mkdir($upload_folder);
        }

        for ($i = 0; $i < count($_FILES['images']['name']); $i++) {

            $file_name = $_FILES['images']['name'][$i];
            $tmp = $_FILES['images']['tmp_name'][$i];

            if ($file_name != "") {

                $new_name = time() . "_" . $i . "_" . $file_name;
                $path = $upload_folder . $new_name;

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

<?php include "navbar.php"; ?>

<!DOCTYPE html>
<html>
<head>
<title>Add Cabin</title>
<link rel="stylesheet" href="style.css">
</head>

<body>

<div class="form-box">

<h2>Add Cabin</h2>

<p><?= $message ?></p>

<form method="POST" enctype="multipart/form-data">

<input type="text" name="name" placeholder="Cabin Name" required>

<textarea name="description" placeholder="Description"></textarea>

<input type="text" name="location" placeholder="Location" required>

<input type="number" name="price" placeholder="Price per night" required>

<input type="number" name="guests" placeholder="Max guests" required>

<input type="file" name="images[]" multiple>

<button name="add">Add Cabin</button>

</form>

</div>

</body>
</html>