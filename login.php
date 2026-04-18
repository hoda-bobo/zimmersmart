<?php
session_start();
include "connection.php";

$message = "";

if (isset($_POST['login'])) {

    $email = trim($_POST['email']);
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT id, first_name, last_name, password, user_type FROM users WHERE email=?");

    if (!$stmt) {
        die("SQL ERROR: " . $conn->error);
    }

    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {

        if (password_verify($password, $user['password'])) {

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name'] = $user['last_name'];
            $_SESSION['name'] = $user['first_name'] . " " . $user['last_name'];
            $_SESSION['user_type'] = $user['user_type'];

            header("Location: home.php");
            exit();

        } else {
            $message = "Wrong password";
        }

    } else {
        $message = "User not found";
    }
}
?>

<?php include "navbar.php"; ?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Login</title>
<link rel="stylesheet" href="style.css">
</head>

<body>

<div class="container">
    <div class="card">
        <h2>Login</h2>

        <div class="msg"><?= $message ?></div>

        <form method="POST">
            <input type="email" name="email" required>
            <input type="password" name="password" required>
            <button type="submit" name="login">Login</button>
        </form>
    </div>
</div>

</body>
</html>