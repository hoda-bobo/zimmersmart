<?php
include "header.php";
include "connection.php";

$message = "";

if (isset($_POST['register'])) {

    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $phone = trim($_POST['phone']);

    $user_type = "customer";

    $first_name = htmlspecialchars($first_name);
    $last_name = htmlspecialchars($last_name);
    $email = htmlspecialchars($email);
    $phone = htmlspecialchars($phone);

    if ($first_name == "" || $last_name == "" || $email == "" || $password == "") {

        $message = "Fill all required fields";

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
                INSERT INTO users (first_name, last_name, email, password, user_type, phone)
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            $stmt->bind_param("ssssss",
                $first_name,
                $last_name,
                $email,
                $hashed,
                $user_type,
                $phone
            );

            if ($stmt->execute()) {
                echo "
                <div class='success-message'>
                    Registration successful! Redirecting to login...
                </div>

                <script>
                    setTimeout(function() {
                        window.location.href = 'login.php';
                    }, 2000);
                </script>
                ";
                exit();
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

<body class="register-page">

<div class="container">

<div class="card">

<h2>Register</h2>

<div class="msg"><?= $message ?></div>

<form method="POST" autocomplete="off">

    <!-- טריק נגד autofill -->
    <input type="text" name="fake_user" style="display:none">
    <input type="password" name="fake_pass" style="display:none">

    <input type="text" name="first_name" placeholder="First Name" required>

    <input type="text" name="last_name" placeholder="Last Name" required>

    <input type="email" name="email" placeholder="Email" required>

    <input type="text" name="phone" placeholder="Phone">

    <input type="password" name="password"
           placeholder="Password"
           autocomplete="new-password"
           readonly
           onfocus="this.removeAttribute('readonly');"
           required>

    <button type="submit" name="register">Register</button>

    <div class="login-link">
        Already have an account? <a href="login.php">Login</a>
    </div>

</form>

</div>

</div>

</body>
</html>