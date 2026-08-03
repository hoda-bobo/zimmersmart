<?php
session_start();

include "connection.php";
include "language.php";
include "lead_helper.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (!isset($_GET['cabin_id'], $_GET['start_date'], $_GET['end_date'])) {
    die(t('payment_missing_data', 'Missing booking data.'));
}

$user_id = (int) $_SESSION['user_id'];
$cabin_id = (int) $_GET['cabin_id'];
$start_date = trim($_GET['start_date']);
$end_date = trim($_GET['end_date']);
$guests = isset($_GET['guests']) ? max(0, (int) $_GET['guests']) : 0;
$message = "";

/* Validate dates */
$start_object = DateTime::createFromFormat('Y-m-d', $start_date);
$end_object = DateTime::createFromFormat('Y-m-d', $end_date);

if (
    !$start_object ||
    !$end_object ||
    $start_object->format('Y-m-d') !== $start_date ||
    $end_object->format('Y-m-d') !== $end_date ||
    $start_object >= $end_object
) {
    die(t('payment_invalid_dates', 'Invalid dates.'));
}

$nights = $start_object->diff($end_object)->days;

/* Save lead */
$lead_type = "payment_started_no_booking";
$notes = "User started payment process but did not complete booking yet";

$checkLead = $conn->prepare("
    SELECT id
    FROM leads
    WHERE user_id = ? AND cabin_id = ? AND lead_type = ?
    LIMIT 1
");

if ($checkLead) {
    $checkLead->bind_param("iis", $user_id, $cabin_id, $lead_type);
    $checkLead->execute();
    $leadResult = $checkLead->get_result();

    if ($leadResult->num_rows === 0) {
        addLead($conn, $user_id, $cabin_id, $lead_type, $notes);
    }

    $checkLead->close();
}

/* Cabin */
$cabin_stmt = $conn->prepare("
    SELECT id, name, price_per_night, max_guests
    FROM cabins
    WHERE id = ?
    LIMIT 1
");

$cabin_stmt->bind_param("i", $cabin_id);
$cabin_stmt->execute();
$cabin = $cabin_stmt->get_result()->fetch_assoc();
$cabin_stmt->close();

if (!$cabin) {
    die(t('payment_cabin_not_found', 'Cabin not found.'));
}

/* User level */
$user_level = 'new';

$user_stmt = $conn->prepare("
    SELECT user_level
    FROM users
    WHERE id = ?
    LIMIT 1
");

if ($user_stmt) {
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $user = $user_stmt->get_result()->fetch_assoc();
    $user_stmt->close();

    if (!empty($user['user_level'])) {
        $user_level = $user['user_level'];
    }
}

$discount = 0;

if ($user_level === 'vip') {
    $discount = 0.15;
} elseif ($user_level === 'regular') {
    $discount = 0.10;
}

/* Services */
$services_string = $_GET['services'] ?? "";
$services_array = [];

if ($services_string !== "") {
    foreach (explode(",", $services_string) as $service_id) {
        $service_id = (int) trim($service_id);

        if ($service_id > 0) {
            $services_array[] = $service_id;
        }
    }

    $services_array = array_values(array_unique($services_array));
}

$services_total = 0;
$services_names = [];

foreach ($services_array as $service_id) {
    $srv = $conn->prepare("
        SELECT service_name, price
        FROM services
        WHERE service_id = ?
        LIMIT 1
    ");

    if (!$srv) {
        continue;
    }

    $srv->bind_param("i", $service_id);
    $srv->execute();
    $service = $srv->get_result()->fetch_assoc();
    $srv->close();

    if ($service) {
        $services_total += (float) $service['price'];
        $services_names[] = $service['service_name'];
    }
}

/* Base price */
$cabin_price = $nights * (float) $cabin['price_per_night'];
$base_price = $cabin_price + $services_total;

/* Smart pricing */
$pricing_details = [];
$total_increase_percent = 0;

$rules = $conn->prepare("
    SELECT *
    FROM pricing_rules
    WHERE cabin_id = ?
");

if ($rules) {
    $rules->bind_param("i", $cabin_id);
    $rules->execute();
    $rules_result = $rules->get_result();

    while ($rule = $rules_result->fetch_assoc()) {
        $apply = false;
        $rule_type = $rule['rule_type'] ?? '';

        if (in_array($rule_type, ['season', 'holiday', 'custom_date'], true)) {
            if (!empty($rule['start_date']) && !empty($rule['end_date'])) {
                if (!($end_date <= $rule['start_date'] || $start_date >= $rule['end_date'])) {
                    $apply = true;
                }
            }
        }

        if ($rule_type === 'guest_count') {
            $min = isset($rule['min_guests']) ? (int) $rule['min_guests'] : 0;
            $max = isset($rule['max_guests']) ? (int) $rule['max_guests'] : 999;

            if ($guests >= $min && $guests <= $max) {
                $apply = true;
            }
        }

        if ($rule_type === 'weekend') {
            $day = (int) date('N', strtotime($start_date));

            if (in_array($day, [4, 5, 6], true)) {
                $apply = true;
            }
        }

        if ($apply) {
            $multiplier = isset($rule['multiplier']) ? (float) $rule['multiplier'] : 1;
            $increase = round(($multiplier - 1) * 100, 1);
            $increase = max(0, min($increase, 50));
            $total_increase_percent += $increase;

            $pricing_details[] = [
                'name' => $rule['rule_name'] ?? t('payment_pricing_rule', 'Pricing rule'),
                'increase' => $increase,
                'reason' => $rule['reason'] ?? ''
            ];
        }
    }

    $rules->close();
}

$total_increase_percent = min($total_increase_percent, 50);
$pricing_multiplier = 1 + ($total_increase_percent / 100);
$price_after_rules = $base_price * $pricing_multiplier;

/* Demand pricing */
$month = (int) date('m', strtotime($start_date));
$year = (int) date('Y', strtotime($start_date));
$booking_count = 0;

$demand = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM bookings
    WHERE cabin_id = ?
      AND MONTH(start_date) = ?
      AND YEAR(start_date) = ?
      AND status = 'confirmed'
");

if ($demand) {
    $demand->bind_param("iii", $cabin_id, $month, $year);
    $demand->execute();
    $demand_result = $demand->get_result()->fetch_assoc();
    $demand->close();

    $booking_count = isset($demand_result['total'])
        ? (int) $demand_result['total']
        : 0;
}

$demand_multiplier = 1;
$demand_reason = t(
    'payment_normal_demand',
    'Normal demand. No demand increase was added.'
);

if ($booking_count > 10) {
    $demand_multiplier = 1.20;
    $demand_reason = t(
        'payment_high_demand',
        'High demand detected for this month.'
    );
} elseif ($booking_count > 5) {
    $demand_multiplier = 1.10;
    $demand_reason = t(
        'payment_medium_demand',
        'Medium demand detected for this month.'
    );
}

$price_before_discount = $price_after_rules * $demand_multiplier;
$final_price = round($price_before_discount * (1 - $discount), 2);

/* Commission calculation */
$admin_commission_rate = 0.10;
$admin_commission = round($final_price * $admin_commission_rate, 2);
$owner_revenue = round($final_price - $admin_commission, 2);

/* Payment */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay'])) {
    $card_number = preg_replace('/\D/', '', $_POST['card_number'] ?? '');
    $expiry_date = trim($_POST['expiry_date'] ?? '');
    $cvv = preg_replace('/\D/', '', $_POST['cvv'] ?? '');

    if (strlen($card_number) < 13 || strlen($card_number) > 19) {
        $message = t('payment_invalid_card', 'Please enter a valid card number.');
    } elseif (!preg_match('/^(0[1-9]|1[0-2])\/\d{2}$/', $expiry_date)) {
        $message = t('payment_invalid_expiry', 'Please enter a valid expiry date.');
    } elseif (strlen($cvv) < 3 || strlen($cvv) > 4) {
        $message = t('payment_invalid_cvv', 'Please enter a valid CVV.');
    } else {
        $check_booking = $conn->prepare("
            SELECT id
            FROM bookings
            WHERE cabin_id = ?
              AND status != 'cancelled'
              AND NOT (end_date <= ? OR start_date >= ?)
            LIMIT 1
        ");

        $check_booking->bind_param("iss", $cabin_id, $start_date, $end_date);
        $check_booking->execute();
        $booking_exists = $check_booking->get_result()->num_rows > 0;
        $check_booking->close();

        $blocked_exists = false;

        $check_blocked = $conn->prepare("
            SELECT id
            FROM cabin_unavailable_dates
            WHERE cabin_id = ?
              AND NOT (end_date <= ? OR start_date >= ?)
            LIMIT 1
        ");

        if ($check_blocked) {
            $check_blocked->bind_param("iss", $cabin_id, $start_date, $end_date);
            $check_blocked->execute();
            $blocked_exists = $check_blocked->get_result()->num_rows > 0;
            $check_blocked->close();
        }

        if ($booking_exists) {
            $message = t(
                'payment_dates_booked',
                'Selected dates are already booked.'
            );
        } elseif ($blocked_exists) {
            $message = t(
                'payment_cabin_unavailable',
                'This cabin is unavailable for the selected dates.'
            );
        } else {
            $insert = $conn->prepare("
                INSERT INTO bookings
                (cabin_id, user_id, start_date, end_date, total_price, status)
                VALUES (?, ?, ?, ?, ?, 'confirmed')
            ");

            $insert->bind_param(
                "iissd",
                $cabin_id,
                $user_id,
                $start_date,
                $end_date,
                $final_price
            );

            if ($insert->execute()) {
                $booking_id = $conn->insert_id;
                $insert->close();

                $update = $conn->prepare("
                    UPDATE users
                    SET total_bookings = COALESCE(total_bookings, 0) + 1,
                        loyalty_points = COALESCE(loyalty_points, 0) + 10
                    WHERE id = ?
                ");

                if ($update) {
                    $update->bind_param("i", $user_id);
                    $update->execute();
                    $update->close();
                }

                $get = $conn->prepare("
                    SELECT total_bookings
                    FROM users
                    WHERE id = ?
                    LIMIT 1
                ");

                $total_bookings = 0;

                if ($get) {
                    $get->bind_param("i", $user_id);
                    $get->execute();
                    $user_after = $get->get_result()->fetch_assoc();
                    $get->close();

                    $total_bookings = (int) ($user_after['total_bookings'] ?? 0);
                }

                if ($total_bookings >= 10) {
                    $new_level = 'vip';
                } elseif ($total_bookings >= 3) {
                    $new_level = 'regular';
                } else {
                    $new_level = 'new';
                }

                $level_update = $conn->prepare("
                    UPDATE users
                    SET user_level = ?
                    WHERE id = ?
                ");

                if ($level_update) {
                    $level_update->bind_param("si", $new_level, $user_id);
                    $level_update->execute();
                    $level_update->close();
                }

                $deleteLeads = $conn->prepare("
                    DELETE FROM leads
                    WHERE user_id = ? AND cabin_id = ?
                ");

                if ($deleteLeads) {
                    $deleteLeads->bind_param("ii", $user_id, $cabin_id);
                    $deleteLeads->execute();
                    $deleteLeads->close();
                }

                header(
                    "Location: bookingconfirmation.php?booking_id=" . $booking_id
                );
                exit();
            }

            $message = t(
                'payment_booking_error',
                'Error saving booking.'
            );
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= current_language() ?>"
      dir="<?= is_rtl() ? 'rtl' : 'ltr' ?>">

<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title><?= htmlspecialchars(t('payment_page_title', 'Payment')) ?></title>

    <link rel="stylesheet"
          href="style.css?v=<?= time() ?>">

    <style>
        .payment-page-fixed {
            min-height: 100vh;
            padding: 48px 20px;
            background: #E8F0F6;
            color: #183153;
            box-sizing: border-box;
        }

        .payment-card-fixed {
            width: 100%;
            max-width: 820px;
            margin: 0 auto;
            padding: 38px;
            background: #FFFFFF;
            border-radius: 28px;
            box-shadow: 0 20px 45px rgba(24, 49, 83, 0.12);
            box-sizing: border-box;
        }

        .payment-card-fixed h1 {
            margin: 0 0 30px;
            color: #183153;
            text-align: center;
            font-size: 34px;
        }

        .payment-summary-section {
            margin-bottom: 22px;
            padding: 22px;
            border: 1px solid #E8F0F6;
            border-radius: 16px;
            background: #FFFFFF;
        }

        .payment-summary-section h2 {
            margin: 0 0 16px;
            color: #183153;
            font-size: 21px;
        }

        .payment-summary-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px 22px;
        }

        .payment-summary-grid p,
        .payment-summary-section p {
            margin: 0;
            color: #355D7D;
            line-height: 1.7;
        }

        .payment-summary-grid strong {
            color: #183153;
        }

        .payment-rule-box {
            margin-top: 12px;
            padding: 15px;
            border-radius: 12px;
            background: #E8F0F6;
        }

        .payment-rule-box strong {
            display: block;
            margin-bottom: 5px;
            color: #183153;
        }

        .payment-total-fixed {
            margin: 28px 0;
            padding: 26px;
            border-radius: 18px;
            background: #183153;
            color: #FFFFFF;
            text-align: center;
        }

        .payment-total-fixed span {
            display: block;
            margin-bottom: 7px;
            font-size: 18px;
        }

        .payment-total-fixed strong {
            display: block;
            font-size: 44px;
        }

        .payment-error-fixed {
            margin: 0 0 22px;
            padding: 15px;
            border: 1px solid #7895AD;
            border-radius: 12px;
            background: #E8F0F6;
            color: #183153;
            font-weight: 700;
            text-align: center;
        }

        .payment-form-fixed h2 {
            margin: 0 0 22px;
            color: #183153;
            text-align: center;
            font-size: 24px;
        }

        .payment-field-fixed {
            margin-bottom: 19px;
        }

        .payment-field-fixed label {
            display: block !important;
            margin-bottom: 8px;
            color: #183153 !important;
            font-size: 16px;
            font-weight: 700;
            text-align: start;
        }

        .payment-field-fixed input {
            display: block !important;
            width: 100% !important;
            min-height: 56px;
            padding: 14px 16px !important;
            border: 1px solid #7895AD !important;
            border-radius: 11px !important;
            background: #FFFFFF !important;
            color: #183153 !important;
            font-size: 17px !important;
            font-family: inherit;
            opacity: 1 !important;
            visibility: visible !important;
            box-sizing: border-box;
            outline: none;
        }

        .payment-field-fixed input::placeholder {
            color: #7895AD !important;
            opacity: 1 !important;
        }

        .payment-field-fixed input:focus {
            border-color: #355D7D !important;
            box-shadow: 0 0 0 4px rgba(53, 93, 125, 0.13);
        }

        .payment-fields-row-fixed {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .payment-demo-fixed {
            margin: 3px 0 20px;
            padding: 17px;
            border-radius: 13px;
            background: #E8F0F6;
            color: #183153;
            text-align: center;
        }

        .payment-demo-fixed strong {
            display: block;
            margin-bottom: 9px;
        }

        .payment-demo-values-fixed {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 8px 18px;
            direction: ltr;
            font-weight: 700;
        }

        .payment-secure-fixed {
            margin: 18px 0;
            color: #355D7D;
            text-align: center;
        }

        .payment-submit-fixed {
            display: block !important;
            width: 100% !important;
            min-height: 60px;
            padding: 16px 22px !important;
            border: 0 !important;
            border-radius: 12px !important;
            background: #183153 !important;
            color: #FFFFFF !important;
            font-size: 19px !important;
            font-family: inherit;
            font-weight: 700 !important;
            cursor: pointer;
            opacity: 1 !important;
            visibility: visible !important;
        }

        .payment-submit-fixed:hover {
            background: #355D7D !important;
        }

        @media (max-width: 650px) {
            .payment-page-fixed {
                padding: 24px 12px;
            }

            .payment-card-fixed {
                padding: 24px 17px;
                border-radius: 20px;
            }

            .payment-summary-grid,
            .payment-fields-row-fixed {
                grid-template-columns: 1fr;
            }

            .payment-total-fixed strong {
                font-size: 37px;
            }
        }
    </style>
</head>

<body>

<?php include "navbar.php"; ?>

<main class="payment-page-fixed">

    <section class="payment-card-fixed">

        <h1><?= htmlspecialchars(t('payment_summary', 'Payment Summary')) ?></h1>

        <div class="payment-summary-section">

            <h2><?= htmlspecialchars(t('payment_booking_details', 'Booking Details')) ?></h2>

            <div class="payment-summary-grid">

                <p>
                    <strong><?= htmlspecialchars(t('cabin', 'Cabin')) ?>:</strong>
                    <?= htmlspecialchars($cabin['name']) ?>
                </p>

                <p>
                    <strong><?= htmlspecialchars(t('from', 'From')) ?>:</strong>
                    <?= htmlspecialchars($start_date) ?>
                </p>

                <p>
                    <strong><?= htmlspecialchars(t('to', 'To')) ?>:</strong>
                    <?= htmlspecialchars($end_date) ?>
                </p>

                <p>
                    <strong><?= htmlspecialchars(t('nights', 'Nights')) ?>:</strong>
                    <?= (int) $nights ?>
                </p>

                <?php if ($guests > 0): ?>
                    <p>
                        <strong><?= htmlspecialchars(t('guests', 'Guests')) ?>:</strong>
                        <?= (int) $guests ?>
                    </p>
                <?php endif; ?>

            </div>

        </div>

        <div class="payment-summary-section">

            <h2><?= htmlspecialchars(t('payment_base_price', 'Base Price')) ?></h2>

            <div class="payment-summary-grid">

                <p>
                    <strong><?= htmlspecialchars(t('payment_cabin_price', 'Cabin Price')) ?>:</strong>
                    ₪<?= number_format($cabin_price, 2) ?>
                </p>

                <p>
                    <strong><?= htmlspecialchars(t('payment_services_price', 'Services Price')) ?>:</strong>
                    ₪<?= number_format($services_total, 2) ?>
                </p>

            </div>

        </div>

        <?php if (!empty($services_names)): ?>

            <div class="payment-summary-section">

                <h2><?= htmlspecialchars(t('payment_selected_services', 'Selected Services')) ?></h2>

                <?php foreach ($services_names as $service_name): ?>
                    <p>✓ <?= htmlspecialchars($service_name) ?></p>
                <?php endforeach; ?>

            </div>

        <?php endif; ?>

        <div class="payment-summary-section">

            <h2><?= htmlspecialchars(t('payment_price_explanation', 'Why did the price change?')) ?></h2>

            <?php if (empty($pricing_details)): ?>

                <div class="payment-rule-box">
                    <p>
                        <?= htmlspecialchars(
                            t(
                                'payment_no_special_rules',
                                'No special owner pricing rules were applied for these dates.'
                            )
                        ) ?>
                    </p>
                </div>

            <?php else: ?>

                <?php foreach ($pricing_details as $detail): ?>

                    <div class="payment-rule-box">

                        <strong>
                            <?= htmlspecialchars($detail['name']) ?>:
                            +<?= number_format((float) $detail['increase'], 1) ?>%
                        </strong>

                        <?php if ($detail['reason'] !== ''): ?>
                            <p><?= htmlspecialchars($detail['reason']) ?></p>
                        <?php endif; ?>

                    </div>

                <?php endforeach; ?>

            <?php endif; ?>

            <div class="payment-rule-box">

                <strong>
                    <?= htmlspecialchars(t('payment_demand_pricing', 'Demand Pricing')) ?>:
                    ×<?= number_format($demand_multiplier, 2) ?>
                </strong>

                <p><?= htmlspecialchars($demand_reason) ?></p>

            </div>

            <?php if ($discount > 0): ?>

                <div class="payment-rule-box">

                    <strong>
                        <?= htmlspecialchars(t('payment_loyalty_discount', 'Loyalty Discount')) ?>:
                        -<?= number_format($discount * 100, 0) ?>%
                    </strong>

                    <p>
                        <?= htmlspecialchars(
                            t(
                                'payment_loyalty_message',
                                'You received a discount according to your customer level.'
                            )
                        ) ?>
                    </p>

                </div>

            <?php endif; ?>

        </div>

        <div class="payment-total-fixed">

            <span><?= htmlspecialchars(t('payment_total_price', 'Total Price')) ?></span>

            <strong>₪<?= number_format($final_price, 2) ?></strong>

        </div>

        <?php if ($message !== ''): ?>
            <div class="payment-error-fixed">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <form method="POST"
              class="payment-form-fixed"
              autocomplete="off">

            <h2><?= htmlspecialchars(t('payment_card_details', 'Credit Card Details')) ?></h2>

            <div class="payment-field-fixed">

                <label for="card_number">
                    <?= htmlspecialchars(t('payment_card_number', 'Card Number')) ?>
                </label>

                <input type="text"
                       id="card_number"
                       name="card_number"
                       placeholder="<?= htmlspecialchars(t('payment_card_placeholder', '1234 5678 9012 3456')) ?>"
                       inputmode="numeric"
                       maxlength="23"
                       autocomplete="cc-number"
                       required>

            </div>

            <div class="payment-fields-row-fixed">

                <div class="payment-field-fixed">

                    <label for="expiry_date">
                        <?= htmlspecialchars(t('payment_expiry_date', 'Expiry Date')) ?>
                    </label>

                    <input type="text"
                           id="expiry_date"
                           name="expiry_date"
                           placeholder="<?= htmlspecialchars(t('payment_expiry_placeholder', 'MM/YY')) ?>"
                           inputmode="numeric"
                           maxlength="5"
                           autocomplete="cc-exp"
                           required>

                </div>

                <div class="payment-field-fixed">

                    <label for="cvv">
                        <?= htmlspecialchars(t('payment_cvv', 'CVV')) ?>
                    </label>

                    <input type="password"
                           id="cvv"
                           name="cvv"
                           placeholder="<?= htmlspecialchars(t('payment_cvv_placeholder', '123')) ?>"
                           inputmode="numeric"
                           maxlength="4"
                           autocomplete="cc-csc"
                           required>

                </div>

            </div>

            <div class="payment-demo-fixed">

                <strong><?= htmlspecialchars(t('payment_demo_card', 'Demo card details')) ?></strong>

                <div class="payment-demo-values-fixed">
                    <span>4580 0000 0000 0000</span>
                    <span>12/30</span>
                    <span>123</span>
                </div>

            </div>

            <p class="payment-secure-fixed">
                🔒
                <?= htmlspecialchars(
                    t(
                        'payment_secure',
                        'Secure demo payment. Card information is not saved.'
                    )
                ) ?>
            </p>

           <button
    type="submit"
    name="pay"
    class="payment-submit-fixed"
    style="
        display:block !important;
        width:100% !important;
        min-height:60px !important;
        padding:16px 22px !important;
        border:none !important;
        border-radius:12px !important;
        background-color:#183153 !important;
        color:#FFFFFF !important;
        font-size:19px !important;
        font-weight:700 !important;
        opacity:1 !important;
        visibility:visible !important;
        cursor:pointer !important;
    "
>
    <?= htmlspecialchars(t('payment_pay_now', 'Pay Now')) ?>
    — ₪<?= number_format($final_price, 2) ?>
</button>

        </form>

    </section>

</main>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const cardNumber = document.getElementById("card_number");
    const expiryDate = document.getElementById("expiry_date");
    const cvv = document.getElementById("cvv");

    if (cardNumber) {
        cardNumber.addEventListener("input", function () {
            let value = this.value.replace(/\D/g, "").substring(0, 19);
            this.value = value.replace(/(.{4})/g, "$1 ").trim();
        });
    }

    if (expiryDate) {
        expiryDate.addEventListener("input", function () {
            let value = this.value.replace(/\D/g, "").substring(0, 4);

            if (value.length >= 3) {
                value = value.substring(0, 2) + "/" + value.substring(2);
            }

            this.value = value;
        });
    }

    if (cvv) {
        cvv.addEventListener("input", function () {
            this.value = this.value.replace(/\D/g, "").substring(0, 4);
        });
    }
});
</script>

</body>
</html>
