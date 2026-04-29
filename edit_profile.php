<?php
session_start();
include "connection.php";
include "footer.php"; 

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$message = "";

$stmt = $conn->prepare("SELECT first_name, last_name, email, phone FROM users WHERE id=?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (isset($_POST['update'])) {
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);

    $update = $conn->prepare("
        UPDATE users
        SET first_name=?, last_name=?, email=?, phone=?
        WHERE id=?
    ");
    $update->bind_param("ssssi", $first_name, $last_name, $email, $phone, $user_id);

    if ($update->execute()) {
        $_SESSION['first_name'] = $first_name;
        $_SESSION['last_name'] = $last_name;
        $_SESSION['name'] = $first_name . " " . $last_name;
        $message = "Profile updated successfully";
    } else {
        $message = "Error updating profile";
    }
}
?>

<?php include "navbar.php"; ?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Edit Profile</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="simple-form-box">
    <h2>Edit Profile</h2>

   <div class="message <?= strpos($message, 'success') !== false ? 'success' : 'error' ?>">
    <?= $message ?>
</div>

    <form method="POST">
        <input type="text" name="first_name" value="<?= htmlspecialchars($user['first_name']) ?>" required>
        <input type="text" name="last_name" value="<?= htmlspecialchars($user['last_name']) ?>" required>
        <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
        <input type="text" name="phone" value="<?= htmlspecialchars($user['phone']) ?>">
        <button type="submit" name="update" class="btn">Save Changes</button>
    </form>
</div>

</body>
</html>