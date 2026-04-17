<?php
include "header.php";
include "connection.php";

$message = "";

if (isset($_POST['register'])) {

    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    // כל מי שנרשם = לקוח
    $user_type = "customer";

    $name = htmlspecialchars($name);
    $email = htmlspecialchars($email);

    if ($name == "" || $email == "" || $password == "") {

        $message = "Fill all fields";

    } else {

        $check = $conn->prepare("SELECT id FROM users WHERE email=?");
        $check->bind_param("s", $email);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {

            $message = "Email already exists";

        } else {

            $hashed = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $conn->prepare("
                INSERT INTO users (name, email, password, user_type)
                VALUES (?, ?, ?, ?)
            ");

            $stmt->bind_param("ssss", $name, $email, $hashed, $user_type);

            if ($stmt->execute()) {
                $message = "Registered successfully!";
            } else {
                $message = "Error registering";
            }
        }
    }
}
?>

<?php include "navbar.php"; ?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Register</title>

<link rel="stylesheet" href="style.css">
</head>

<body>

<div class="container">

<div class="card">

<h2>Register</h2>

<div class="msg"><?= $message ?></div>

<form method="POST" autocomplete="off">

    <input type="text" name="name" placeholder="Full Name" required autocomplete="off">

    <input type="email" name="email" placeholder="Email" required autocomplete="off">

    <input type="password" name="password" placeholder="Password" required autocomplete="new-password">

    <button type="submit" name="register">Register</button>

</form>

</div>

</div>

</body>
</html>