<?php
session_start();

include_once __DIR__ . "/connection.php";
require_once __DIR__ . "/language.php";

/* Current language and page direction */
$current_language = $_SESSION['language'] ?? ($_SESSION['lang'] ?? 'en');

if (!in_array($current_language, ['en', 'he'], true)) {
    $current_language = 'en';
}

$page_direction = $current_language === 'he' ? 'rtl' : 'ltr';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if ($_SESSION['user_type'] != 'attraction_owner') {
    die(t('only_attraction_owners_allowed'));
}

$owner_id = (int) $_SESSION['user_id'];
$message = "";
$message_type = "";

/* SAVE ATTRACTION */
if (isset($_POST['save'])) {

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $region = $_POST['region'] ?? '';
    $discount_text = trim($_POST['discount_text'] ?? '');

    $allowed_regions = ['north', 'center', 'south'];

    if (
        $title === "" ||
        $description === "" ||
        $location === "" ||
        !in_array($region, $allowed_regions)
    ) {
        $message = t('fill_required_fields');
        $message_type = "error";
    } else {

        $stmt = $conn->prepare("
            INSERT INTO attractions
            (owner_id, title, description, location, region, discount_text)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "isssss",
            $owner_id,
            $title,
            $description,
            $location,
            $region,
            $discount_text
        );

        if ($stmt->execute()) {
            $message = t('attraction_added_successfully');
            $message_type = "success";
        } else {
            $message = t('error_adding_attraction');
            $message_type = "error";
        }

        $stmt->close();
    }
}

/* DELETE ATTRACTION */
if (isset($_GET['delete'])) {

    $delete_id = (int) $_GET['delete'];

    $stmt = $conn->prepare("
        DELETE FROM attractions
        WHERE id = ? AND owner_id = ?
    ");

    $stmt->bind_param("ii", $delete_id, $owner_id);
    $stmt->execute();
    $stmt->close();

    $_SESSION['attraction_message'] =
        t('attraction_deleted_successfully');

    header("Location: manageattractions.php");
    exit();
}

/* MESSAGE AFTER REDIRECT */
if (isset($_SESSION['attraction_message'])) {
    $message = $_SESSION['attraction_message'];
    $message_type = "success";

    unset($_SESSION['attraction_message']);
}

/* GET MY ATTRACTIONS */
$stmt = $conn->prepare("
    SELECT *
    FROM attractions
    WHERE owner_id = ?
    ORDER BY created_at DESC
");

$stmt->bind_param("i", $owner_id);
$stmt->execute();

$attractions = $stmt->get_result();

include "navbar.php";

?>

<!DOCTYPE html>
<html lang="<?= htmlspecialchars($current_language) ?>"
      dir="<?= $page_direction ?>">

<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title><?= htmlspecialchars(t('manage_attractions')) ?></title>

    <link rel="stylesheet"
          href="style.css?v=<?= time() ?>">
</head>

<body>

<div class="attraction-page">
    <div class="attraction-container">

        <div class="attraction-hero">
            <div>
                <span class="mini-title">
                    <?= htmlspecialchars(t('attraction_owner')) ?>
                </span>

                <h1>
                    <?= htmlspecialchars(t('manage_attractions')) ?>
                </h1>

                <p>
                    <?= htmlspecialchars(
                        t('manage_attractions_description')
                    ) ?>
                </p>
            </div>
        </div>

        <?php if ($message !== "") { ?>
            <div class="<?= $message_type === 'error'
                ? 'error-message'
                : 'success' ?>">

                <?= htmlspecialchars($message) ?>
            </div>
        <?php } ?>

        <div class="attraction-grid">

            <!-- ADD ATTRACTION -->
            <div class="attraction-card">

                <h2>
                    <?= htmlspecialchars(t('add_new_attraction')) ?>
                </h2>

                <p class="helper-text">
                    <?= htmlspecialchars(
                        t('attraction_region_explanation')
                    ) ?>
                </p>

                <form method="POST">

                    <label>
                        <?= htmlspecialchars(t('attraction_title')) ?>
                    </label>

                    <input
                        type="text"
                        name="title"
                        value="<?= htmlspecialchars(
                            $_POST['title'] ?? ''
                        ) ?>"
                        placeholder="<?= htmlspecialchars(
                            t('attraction_title_placeholder')
                        ) ?>"
                        required
                    >

                    <label>
                        <?= htmlspecialchars(t('description')) ?>
                    </label>

                    <textarea
                        name="description"
                        placeholder="<?= htmlspecialchars(
                            t('attraction_description_placeholder')
                        ) ?>"
                        required
                    ><?= htmlspecialchars(
                        $_POST['description'] ?? ''
                    ) ?></textarea>

                    <label>
                        <?= htmlspecialchars(t('location')) ?>
                    </label>

                    <input
                        type="text"
                        name="location"
                        value="<?= htmlspecialchars(
                            $_POST['location'] ?? ''
                        ) ?>"
                        placeholder="<?= htmlspecialchars(
                            t('attraction_location_placeholder')
                        ) ?>"
                        required
                    >

                    <label>
                        <?= htmlspecialchars(t('region')) ?>
                    </label>

                    <select name="region" required>

                        <option value="north"
                            <?= ($_POST['region'] ?? '') === 'north'
                                ? 'selected'
                                : '' ?>>

                            <?= htmlspecialchars(t('north')) ?>
                        </option>

                        <option value="center"
                            <?= ($_POST['region'] ?? '') === 'center'
                                ? 'selected'
                                : '' ?>>

                            <?= htmlspecialchars(t('center')) ?>
                        </option>

                        <option value="south"
                            <?= ($_POST['region'] ?? '') === 'south'
                                ? 'selected'
                                : '' ?>>

                            <?= htmlspecialchars(t('south')) ?>
                        </option>

                    </select>

                    <label>
                        <?= htmlspecialchars(t('discount_text')) ?>
                    </label>

                    <input
                        type="text"
                        name="discount_text"
                        value="<?= htmlspecialchars(
                            $_POST['discount_text'] ?? ''
                        ) ?>"
                        placeholder="<?= htmlspecialchars(
                            t('discount_placeholder')
                        ) ?>"
                    >

                    <button type="submit" name="save">
                        <?= htmlspecialchars(t('save_attraction')) ?>
                    </button>

                </form>
            </div>

            <!-- HOW IT WORKS -->
            <div class="attraction-card info-side">

                <h2>
                    <?= htmlspecialchars(t('how_it_works')) ?>
                </h2>

                <div class="info-line">
                    <b>
                        <?= htmlspecialchars(
                            t('publish_attraction')
                        ) ?>
                    </b>

                    <p>
                        <?= htmlspecialchars(
                            t('publish_attraction_description')
                        ) ?>
                    </p>
                </div>

                <div class="info-line">
                    <b>
                        <?= htmlspecialchars(
                            t('customer_books_cabin')
                        ) ?>
                    </b>

                    <p>
                        <?= htmlspecialchars(
                            t('customer_books_description')
                        ) ?>
                    </p>
                </div>

                <div class="info-line">
                    <b>
                        <?= htmlspecialchars(t('region_match')) ?>
                    </b>

                    <p>
                        <?= htmlspecialchars(
                            t('region_match_description')
                        ) ?>
                    </p>
                </div>

                <div class="info-line">
                    <b>
                        <?= htmlspecialchars(
                            t('discount_appears')
                        ) ?>
                    </b>

                    <p>
                        <?= htmlspecialchars(
                            t('discount_appears_description')
                        ) ?>
                    </p>
                </div>

            </div>
        </div>

        <!-- MY ATTRACTIONS -->
        <div class="attraction-card full">

            <h2>
                <?= htmlspecialchars(t('my_attractions')) ?>
            </h2>

            <?php if ($attractions->num_rows > 0) { ?>

                <div class="table-responsive">

                    <table class="stats-table">

                        <thead>
                        <tr>
                            <th>
                                <?= htmlspecialchars(t('title')) ?>
                            </th>

                            <th>
                                <?= htmlspecialchars(t('location')) ?>
                            </th>

                            <th>
                                <?= htmlspecialchars(t('region')) ?>
                            </th>

                            <th>
                                <?= htmlspecialchars(t('discount')) ?>
                            </th>

                            <th>
                                <?= htmlspecialchars(t('description')) ?>
                            </th>

                            <th>
                                <?= htmlspecialchars(t('action')) ?>
                            </th>
                        </tr>
                        </thead>
<tbody>

<?php while ($row = $attractions->fetch_assoc()) { ?>

<tr>

    <td>
        <?= htmlspecialchars($row['title']) ?>
    </td>

    <td>
        <?= htmlspecialchars($row['location']) ?>
    </td>

    <td>
        <span class="rule-badge">
            <?= htmlspecialchars(t($row['region'])) ?>
        </span>
    </td>

    <td>
        <?= htmlspecialchars($row['discount_text'] ?: '-') ?>
    </td>

    <td>
        <?= htmlspecialchars($row['description']) ?>
    </td>

    <td>
        <a
            class="delete-link"
            href="manageattractions.php?delete=<?= (int)$row['id'] ?>"
            onclick="return confirm('<?= htmlspecialchars(t('delete_attraction_confirmation'), ENT_QUOTES) ?>')"
        >
            <?= htmlspecialchars(t('delete')) ?>
        </a>
    </td>

</tr>

<?php } ?>

</tbody>


                    </table>

                </div>

            <?php } else { ?>

                <p class="empty-message">
                    <?= htmlspecialchars(
                        t('no_attractions_found')
                    ) ?>
                </p>

            <?php } ?>

        </div>

    </div>
</div>

</body>
</html>