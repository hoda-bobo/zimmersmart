<?php
session_start();
include "connection.php";
include "language.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if ($_SESSION['user_type'] != 'owner') {
    die(t('access_denied'));
}

$owner_id = $_SESSION['user_id'];
$message = "";
$selected_cabin_id = isset($_GET['cabin_id']) ? intval($_GET['cabin_id']) : 0;

/* Owner cabins */
$cabins_list = [];
$cabins = $conn->prepare("SELECT id, name, price_per_night FROM cabins WHERE owner_id=? ORDER BY name");
$cabins->bind_param("i", $owner_id);
$cabins->execute();
$res = $cabins->get_result();

while ($row = $res->fetch_assoc()) {
    $cabins_list[] = $row;
}

if ($selected_cabin_id == 0 && count($cabins_list) > 0) {
    $selected_cabin_id = $cabins_list[0]['id'];
}

/* Selected cabin */
$selected_cabin = null;
if ($selected_cabin_id > 0) {
    $stmt = $conn->prepare("
        SELECT id, name, price_per_night, max_guests
        FROM cabins
        WHERE id=? AND owner_id=?
    ");
    $stmt->bind_param("ii", $selected_cabin_id, $owner_id);
    $stmt->execute();
    $selected_cabin = $stmt->get_result()->fetch_assoc();
}

/* Recommendation calculation */
$current_price = $selected_cabin ? $selected_cabin['price_per_night'] : 0;
$current_month = date('m');

$demand_this_year = 0;
$demand_last_year = 0;

if ($selected_cabin) {
    $year_now = date('Y');
    $year_last = $year_now - 1;

    $q = $conn->prepare("
        SELECT COUNT(*) total
        FROM bookings
        WHERE cabin_id=?
        AND MONTH(start_date)=?
        AND YEAR(start_date)=?
        AND status='confirmed'
    ");
    $q->bind_param("iii", $selected_cabin_id, $current_month, $year_now);
    $q->execute();
    $demand_this_year = $q->get_result()->fetch_assoc()['total'];

    $q = $conn->prepare("
        SELECT COUNT(*) total
        FROM bookings
        WHERE cabin_id=?
        AND MONTH(start_date)=?
        AND YEAR(start_date)=?
        AND status='confirmed'
    ");
    $q->bind_param("iii", $selected_cabin_id, $current_month, $year_last);
    $q->execute();
    $demand_last_year = $q->get_result()->fetch_assoc()['total'];
}

$recommended_percent = 0;
$demand_level = t('demand_low');
$recommendation_reason = t('recommendation_low_demand');

if ($demand_this_year >= 10 || $demand_last_year >= 10) {
    $recommended_percent = 20;
    $demand_level = t('demand_very_high');
    $recommendation_reason = t('recommendation_very_high_demand');
} elseif ($demand_this_year >= 6 || $demand_last_year >= 6) {
    $recommended_percent = 15;
    $demand_level = t('demand_high');
    $recommendation_reason = t('recommendation_high_demand');
} elseif ($demand_this_year >= 3 || $demand_last_year >= 3) {
    $recommended_percent = 10;
    $demand_level = t('demand_medium');
    $recommendation_reason = t('recommendation_medium_demand');
}

$recommended_price = $current_price * (1 + ($recommended_percent / 100));

/* Save pricing rule */
if (isset($_POST['save_pricing'])) {
    $cabin_id = intval($_POST['pricing_cabin_id']);
    $rule_type = $_POST['rule_type'];
    $rule_name = trim($_POST['rule_name']);
    $start_date = $_POST['price_start_date'] ?: null;
    $end_date = $_POST['price_end_date'] ?: null;
    $min_guests = $_POST['min_guests'] !== "" ? intval($_POST['min_guests']) : null;
    $max_guests = $_POST['max_guests'] !== "" ? intval($_POST['max_guests']) : null;
    $increase_percent = floatval($_POST['increase_percent']);
    $reason = trim($_POST['reason_text']);

    $multiplier = 1 + ($increase_percent / 100);

    $check = $conn->prepare("SELECT id FROM cabins WHERE id=? AND owner_id=?");
    $check->bind_param("ii", $cabin_id, $owner_id);
    $check->execute();

    if ($check->get_result()->num_rows == 0) {
        $message = t('cabin_not_found');
    } else {
        $insert = $conn->prepare("
            INSERT INTO pricing_rules
            (cabin_id, rule_type, rule_name, start_date, end_date, min_guests, max_guests, multiplier, reason)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$insert) {
            die("SQL Error: " . $conn->error);
        }

        $insert->bind_param(
            "issssiids",
            $cabin_id,
            $rule_type,
            $rule_name,
            $start_date,
            $end_date,
            $min_guests,
            $max_guests,
            $multiplier,
            $reason
        );

        if ($insert->execute()) {
            $message = t('pricing_rule_added');
        } else {
            $message = t('failed_to_save_pricing_rule');
        }
    }
}

/* Save unavailable dates */
if (isset($_POST['save_unavailable'])) {
    $cabin_id = intval($_POST['cabin_id']);
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $reason = trim($_POST['reason']);

    if ($start_date >= $end_date) {
        $message = t('invalid_dates');
    } else {
        $insert = $conn->prepare("
            INSERT INTO cabin_unavailable_dates
            (cabin_id, start_date, end_date, reason)
            VALUES (?, ?, ?, ?)
        ");
        $insert->bind_param("isss", $cabin_id, $start_date, $end_date, $reason);

        if ($insert->execute()) {
            $message = t('unavailable_date_added');
        } else {
            $message = t('failed_to_save_unavailable_dates');
        }
    }
}

/* Delete pricing rule */
if (isset($_GET['delete_rule'])) {
    $delete_id = intval($_GET['delete_rule']);

    $del = $conn->prepare("
        DELETE pr FROM pricing_rules pr
        JOIN cabins c ON pr.cabin_id = c.id
        WHERE pr.id=? AND c.owner_id=?
    ");
    $del->bind_param("ii", $delete_id, $owner_id);
    $del->execute();

    header("Location: pricingavailability.php?cabin_id=".$selected_cabin_id);
    exit();
}

/* Delete blocked date */
if (isset($_GET['delete_block'])) {
    $delete_id = intval($_GET['delete_block']);

    $del = $conn->prepare("
        DELETE cud FROM cabin_unavailable_dates cud
        JOIN cabins c ON cud.cabin_id = c.id
        WHERE cud.id=? AND c.owner_id=?
    ");
    $del->bind_param("ii", $delete_id, $owner_id);
    $del->execute();

    header("Location: pricingavailability.php?cabin_id=".$selected_cabin_id);
    exit();
}

/* Current rules */
$rules = $conn->prepare("
    SELECT pr.*, c.name
    FROM pricing_rules pr
    JOIN cabins c ON pr.cabin_id = c.id
    WHERE c.owner_id=? AND c.id=?
    ORDER BY pr.created_at DESC
");
$rules->bind_param("ii", $owner_id, $selected_cabin_id);
$rules->execute();
$rules_result = $rules->get_result();

/* Blocked dates */
$blocked = $conn->prepare("
    SELECT cud.*, c.name
    FROM cabin_unavailable_dates cud
    JOIN cabins c ON cud.cabin_id = c.id
    WHERE c.owner_id=? AND c.id=?
    ORDER BY cud.start_date DESC
");
$blocked->bind_param("ii", $owner_id, $selected_cabin_id);
$blocked->execute();
$blocked_result = $blocked->get_result();

include "navbar.php";
?>

<!DOCTYPE html>
<html>
<head>
<title><?= t('pricing_availability') ?></title>
<link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
</head>

<body>

<div class="pricing-page">
<div class="pricing-container">

    <div class="pricing-hero">
        <div>
            <span class="mini-title"><?= t('smart_owner_pricing') ?></span>
            <h1><?= t('pricing_availability') ?></h1>
            <p><?= t('pricing_page_description') ?></p>
        </div>
    </div>

    <?php if ($message != "") { ?>
        <div class="success"><?= htmlspecialchars($message) ?></div>
    <?php } ?>

    <div class="cabin-switch-card">
        <h2><?= t('select_cabin') ?></h2>
        <p><?= t('choose_cabin_to_manage') ?></p>

        <form method="GET">
            <select name="cabin_id" onchange="this.form.submit()">
                <?php foreach($cabins_list as $cabin) { ?>
                    <option value="<?= $cabin['id'] ?>" <?= $selected_cabin_id == $cabin['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cabin['name']) ?>
                    </option>
                <?php } ?>
            </select>
        </form>
    </div>

    <?php if ($selected_cabin) { ?>

    <div class="recommendation-card">
        <div>
            <span class="mini-title"><?= t('automatic_recommendation') ?></span>
            <h2><?= htmlspecialchars($selected_cabin['name']) ?></h2>
            <?= t('current_price') ?>: ₪<?= number_format($current_price,2) ?>
        </div>

        <div class="recommend-box">
            <span><?= t('demand_level') ?></span>
            <h3><?= $demand_level ?></h3>
            <p><?= t('this_month_bookings') ?>: <?= $demand_this_year ?></p>
            <p><?= t('same_month_last_year') ?>: <?= $demand_last_year ?></p>
        </div>

        <div class="recommend-box highlight">
            <span><?= t('recommended_increase') ?></span>
            <h3>+<?= $recommended_percent ?>%</h3>
            <p><?= t('recommended_price') ?>:</p>
            <b>₪<?= number_format($recommended_price,2) ?></b>
        </div>
    </div>

    <div class="explain-card">
        <h3><?= t('why_this_recommendation') ?></h3>
        <p><?= htmlspecialchars($recommendation_reason) ?></p>
        <p>
            <?= t('recommendation_explanation') ?>
        </p>
    </div>

    <div class="pricing-grid">

        <div class="pricing-card">
            <h2><?= t('add_pricing_rule') ?></h2>
            <p class="helper-text"><?= t('pricing_rule_helper') ?></p>

            <form method="POST">

                <input type="hidden" name="pricing_cabin_id" value="<?= $selected_cabin_id ?>">

                <label><?= t('pricing_type') ?></label>
                <select name="rule_type" required>
                    <option value="season"><?= t('pricing_type_season') ?></option>
                    <option value="holiday"><?= t('pricing_type_holiday') ?></option>
                    <option value="guest_count"><?= t('pricing_type_guest_count') ?></option>
                    <option value="weekend"><?= t('pricing_type_weekend') ?></option>
                    <option value="custom_date"><?= t('pricing_type_custom_date') ?></option>
                </select>

                <label><?= t('rule_name') ?></label>
                <input type="text" name="rule_name" value="<?= htmlspecialchars(t('recommended_rule_for') . ' ' . $selected_cabin['name']) ?>" required>

                <label><?= t('start_date') ?></label>
                <input type="date" name="price_start_date">

                <label><?= t('end_date') ?></label>
                <input type="date" name="price_end_date">

                <label><?= t('minimum_guests') ?></label>
                <input type="number" name="min_guests" placeholder="<?= t('example_five') ?>">

                <label><?= t('maximum_guests') ?></label>
                <input type="number" name="max_guests" placeholder="<?= t('optional') ?>">

                <label><?= t('price_increase_percentage') ?></label>
                <input type="number" step="0.01" name="increase_percent" value="<?= $recommended_percent ?>" required>

                <label><?= t('reason_shown_to_owner') ?></label>
                <textarea name="reason_text"><?= htmlspecialchars($recommendation_reason) ?></textarea>

                <button type="submit" name="save_pricing"><?= t('save_pricing_rule') ?></button>

            </form>
        </div>

        <div class="pricing-card">
            <h2><?= t('block_unavailable_dates') ?></h2>
            <p class="helper-text"><?= t('blocked_dates_helper') ?></p>

            <form method="POST">

                <input type="hidden" name="cabin_id" value="<?= $selected_cabin_id ?>">

                <label><?= t('start_date') ?></label>
                <input type="date" name="start_date" required>

                <label><?= t('end_date') ?></label>
                <input type="date" name="end_date" required>

                <label><?= t('reason') ?></label>
                <input type="text" name="reason" placeholder="<?= t('maintenance_vacation_private_use') ?>">

                <button type="submit" name="save_unavailable"><?= t('save_unavailable_dates') ?></button>

            </form>
        </div>

    </div>

    <div class="pricing-card full">
        <h2><?= t('current_pricing_rules_for_cabin') ?></h2>

        <table class="stats-table">
            <tr>
                <th><?= t('type') ?></th>
                <th><?= t('pricing_rule') ?></th>
                <th><?= t('dates') ?></th>
                <th><?= t('guests') ?></th>
                <th><?= t('increase') ?></th>
                <th><?= t('reason') ?></th>
                <th><?= t('actions') ?></th>
            </tr>

            <?php while($row = $rules_result->fetch_assoc()) { 
                $increase = round(($row['multiplier'] - 1) * 100, 1);
            ?>
            <tr>
                <td><span class="rule-badge"><?= htmlspecialchars($row['rule_type']) ?></span></td>
                <td><?= htmlspecialchars($row['rule_name']) ?></td>
                <td>
                    <?= $row['start_date'] ? htmlspecialchars($row['start_date']) : t('any') ?>
                    -
                    <?= $row['end_date'] ? htmlspecialchars($row['end_date']) : t('any') ?>
                </td>
                <td>
                    <?= $row['min_guests'] ? htmlspecialchars($row['min_guests']) : t('any') ?>
                    -
                    <?= $row['max_guests'] ? htmlspecialchars($row['max_guests']) : t('any') ?>
                </td>
                <td><b>+<?= $increase ?>%</b></td>
                <td><?=htmlspecialchars(t($row['reason'])) ?></td>
                <td>
                    <a href="pricingavailability.php?cabin_id=<?= $selected_cabin_id ?>&delete_rule=<?= $row['id'] ?>"
                       onclick="return confirm('<?= t('confirm_delete_pricing_rule') ?>')"><?= t('delete') ?></a>
                </td>
            </tr>
            <?php } ?>
        </table>
    </div>

    <div class="pricing-card full">
        <h2><?= t('blocked_dates_for_cabin') ?></h2>

        <table class="stats-table">
            <tr>
                <th><?= t('from') ?></th>
                <th><?= t('to') ?></th>
                <th><?= t('reason') ?></th>
                <th><?= t('actions') ?></th>
            </tr>

            <?php while($row = $blocked_result->fetch_assoc()) { ?>
            <tr>
                <td><?= htmlspecialchars($row['start_date']) ?></td>
                <td><?= htmlspecialchars($row['end_date']) ?></td>
                <td><?= htmlspecialchars($row['reason']) ?></td>
                <td>
                    <a href="pricingavailability.php?cabin_id=<?= $selected_cabin_id ?>&delete_block=<?= $row['id'] ?>"
                       onclick="return confirm('<?= t('confirm_delete_blocked_date') ?>')"><?= t('delete') ?></a>
                </td>
            </tr>
            <?php } ?>
        </table>
    </div>

    <?php } ?>

</div>
</div>

</body>
</html>