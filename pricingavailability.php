<?php
session_start();
include "connection.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if ($_SESSION['user_type'] != 'owner') {
    die("Only owners allowed");
}

$owner_id = $_SESSION['user_id'];
$message = "";

/* cabins of this owner */
$cabins = $conn->prepare("SELECT id, name FROM cabins WHERE owner_id=?");
$cabins->bind_param("i", $owner_id);
$cabins->execute();
$cabins_result = $cabins->get_result();

/* save unavailable dates */
if (isset($_POST['save'])) {
    $cabin_id = intval($_POST['cabin_id']);
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $reason = trim($_POST['reason']);

    if ($start_date >= $end_date) {
        $message = "Invalid dates";
    } else {
        $check = $conn->prepare("
            SELECT id FROM cabins
            WHERE id=? AND owner_id=?
        ");
        $check->bind_param("ii", $cabin_id, $owner_id);
        $check->execute();

        if ($check->get_result()->num_rows == 0) {
            $message = "Cabin not found";
        } else {
            $insert = $conn->prepare("
                INSERT INTO cabin_unavailable_dates
                (cabin_id, start_date, end_date, reason)
                VALUES (?, ?, ?, ?)
            ");
            $insert->bind_param("isss", $cabin_id, $start_date, $end_date, $reason);

            if ($insert->execute()) {
                $message = "Dates blocked successfully";
            } else {
                $message = "Error saving dates";
            }
        }
    }
}

/* delete block */
if (isset($_GET['delete'])) {
    $delete_id = intval($_GET['delete']);

    $del = $conn->prepare("
        DELETE cud FROM cabin_unavailable_dates cud
        JOIN cabins c ON cud.cabin_id = c.id
        WHERE cud.id=? AND c.owner_id=?
    ");
    $del->bind_param("ii", $delete_id, $owner_id);
    $del->execute();

    header("Location: pricingavailability.php");
    exit();
}

/* existing blocked dates */
$blocked = $conn->prepare("
    SELECT cud.*, c.name
    FROM cabin_unavailable_dates cud
    JOIN cabins c ON cud.cabin_id = c.id
    WHERE c.owner_id=?
    ORDER BY cud.start_date DESC
");
$blocked->bind_param("i", $owner_id);
$blocked->execute();
$blocked_result = $blocked->get_result();

include "navbar.php";
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Availability</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="edit-page">
<div class="edit-container">

    <div class="edit-header">
        <h1>Manage Availability</h1>
        <p>Block dates when your cabin is not available</p>
    </div>

    <div class="edit-card">

        <?php if ($message != "") { ?>
            <div class="success"><?= htmlspecialchars($message) ?></div>
        <?php } ?>

        <form method="POST">

            <h3>Block Dates</h3>

            <select name="cabin_id" required>
                <option value="">Select Cabin</option>
                <?php while($cabin = $cabins_result->fetch_assoc()) { ?>
                    <option value="<?= $cabin['id'] ?>">
                        <?= htmlspecialchars($cabin['name']) ?>
                    </option>
                <?php } ?>
            </select>

            <input type="date" name="start_date" required>
            <input type="date" name="end_date" required>
            <input type="text" name="reason" placeholder="Reason: maintenance, vacation, private use">

            <button type="submit" name="save">Save Unavailable Dates</button>

        </form>

    </div>

    <div class="edit-card" style="margin-top:25px;">
        <h3>Blocked Dates</h3>

        <table class="stats-table">
            <tr>
                <th>Cabin</th>
                <th>From</th>
                <th>To</th>
                <th>Reason</th>
                <th>Action</th>
            </tr>

            <?php while($row = $blocked_result->fetch_assoc()) { ?>
                <tr>
                    <td><?= htmlspecialchars($row['name']) ?></td>
                    <td><?= htmlspecialchars($row['start_date']) ?></td>
                    <td><?= htmlspecialchars($row['end_date']) ?></td>
                    <td><?= htmlspecialchars($row['reason']) ?></td>
                    <td>
                        <a href="pricingavailability.php?delete=<?= $row['id'] ?>"
                           onclick="return confirm('Delete this blocked date?')">
                           Delete
                        </a>
                    </td>
                </tr>
            <?php } ?>

        </table>
    </div>

</div>
</div>

</body>
</html>