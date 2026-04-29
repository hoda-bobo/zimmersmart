<?php
session_start();
include "connection.php";


if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$message = "";

if (isset($_POST['change'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];

    $stmt = $conn->prepare("SELECT password FROM users WHERE id=?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if ($user && password_verify($current_password, $user['password'])) {
        $new_hashed = password_hash($new_password, PASSWORD_DEFAULT);

        $update = $conn->prepare("UPDATE users SET password=? WHERE id=?");
        $update->bind_param("si", $new_hashed, $user_id);

        if ($update->execute()) {
            $message = "Password changed successfully";
        } else {
            $message = "Error changing password";
        }
    } else {
        $message = "Current password is incorrect";
    }
}
?>

<?php include "navbar.php"; ?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Change Password</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="simple-form-box">
    <h2>Change Password</h2>

   <div class="message <?= strpos($message, 'successfully') !== false ? 'success' : 'error' ?>">
    <?= $message ?>
</div>

    <form method="POST">
        <input type="password" name="current_password" placeholder="Current Password" required>
        <input type="password" name="new_password" placeholder="New Password" required>
        <button type="submit" name="change" class="btn">Update Password</button>
    </form>
</div>
<?php include "footer.php"; ?>
</body>
</html>