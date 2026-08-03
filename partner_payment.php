<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include "connection.php";
include "language.php";
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
$user_id=(int)$_SESSION['user_id'];
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));
$csrf_token=$_SESSION['csrf_token'];
$request_id=(int)($_GET['request_id'] ?? $_POST['request_id'] ?? 0);
if ($request_id<=0) { header("Location: request_role.php"); exit(); }
$stmt=$conn->prepare("SELECT rr.id,rr.user_id,rr.requested_role,rr.business_name,rr.payment_amount,rr.payment_status,rr.request_status,rr.created_at,u.first_name,u.last_name,u.email FROM role_requests rr INNER JOIN users u ON rr.user_id=u.id WHERE rr.id=? AND rr.user_id=? LIMIT 1");
if (!$stmt) die("SQL ERROR: ".$conn->error);
$stmt->bind_param("ii",$request_id,$user_id); $stmt->execute();
$role_request=$stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$role_request) { header("Location: request_role.php"); exit(); }
$message=""; $message_type="";
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $submitted_token=$_POST['csrf_token'] ?? "";
    $cardholder_name=trim($_POST['cardholder_name'] ?? "");
    $card_number=preg_replace('/\D/','',$_POST['card_number'] ?? "");
    $expiry_date=trim($_POST['expiry_date'] ?? "");
    $cvv=preg_replace('/\D/','',$_POST['cvv'] ?? "");
    if (!hash_equals($_SESSION['csrf_token'],$submitted_token)) { $message=t('partner_payment_invalid_token'); $message_type='error'; }
    elseif ($role_request['payment_status']==='paid') { $message=t('partner_payment_already_paid'); $message_type='success'; }
    elseif ($role_request['request_status']!=='pending') { $message=t('partner_payment_unavailable'); $message_type='error'; }
    elseif (mb_strlen($cardholder_name)<3) { $message=t('partner_payment_enter_name'); $message_type='error'; }
    elseif (strlen($card_number)<13 || strlen($card_number)>19) { $message=t('partner_payment_invalid_card'); $message_type='error'; }
    elseif (!preg_match('/^(0[1-9]|1[0-2])\/[0-9]{2}$/',$expiry_date)) { $message=t('partner_payment_invalid_expiry'); $message_type='error'; }
    elseif (strlen($cvv)<3 || strlen($cvv)>4) { $message=t('partner_payment_invalid_cvv'); $message_type='error'; }
    else {
        try {
            $conn->begin_transaction();
            $lock=$conn->prepare("SELECT payment_status,request_status FROM role_requests WHERE id=? AND user_id=? LIMIT 1 FOR UPDATE");
            if (!$lock) throw new Exception($conn->error);
            $lock->bind_param("ii",$request_id,$user_id); $lock->execute();
            $locked=$lock->get_result()->fetch_assoc(); $lock->close();
            if (!$locked) throw new Exception(t('partner_payment_request_not_found'));
            if ($locked['payment_status']==='paid') throw new Exception(t('partner_payment_already_paid'));
            if ($locked['request_status']!=='pending') throw new Exception(t('partner_payment_not_pending'));
            $pay=$conn->prepare("UPDATE role_requests SET payment_status='paid' WHERE id=? AND user_id=?");
            if (!$pay) throw new Exception($conn->error);
            $pay->bind_param("ii",$request_id,$user_id);
            if (!$pay->execute()) throw new Exception(t('partner_payment_failed'));
            $pay->close(); $conn->commit();
            header("Location: partner_payment.php?request_id=".$request_id."&success=1"); exit();
        } catch (Throwable $e) {
            $conn->rollback(); $message=$e->getMessage(); $message_type='error';
        }
    }
}
if (isset($_GET['success']) && $_GET['success']==='1') {
    $role_request['payment_status']='paid';
    $message=t('partner_payment_success_message'); $message_type='success';
}
function paymentRoleName(string $role): string {
    return $role==='owner' ? t('partner_role_cabin_owner') : t('partner_role_attraction_owner');
}
?>
<!DOCTYPE html>
<html lang="<?= current_language() ?>" dir="<?= is_rtl() ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= htmlspecialchars(t('partner_payment_page_title')) ?></title>
<link rel="stylesheet" href="style.css?v=<?= time() ?>">
</head>
<body>
<?php include "navbar.php"; ?>
<main class="partner-payment-page">
<section class="partner-payment-container">
<div class="partner-payment-summary-card">
<span class="partner-payment-label"><?= htmlspecialchars(t('partner_payment_registration')) ?></span>
<h1><?= htmlspecialchars(t('partner_payment_complete_title')) ?></h1>
<p><?= htmlspecialchars(t('partner_payment_intro')) ?></p>
<div class="partner-demo-warning">
    <span>💳</span>
    <div>
        <strong><?= htmlspecialchars(t('partner_payment_secure_title')) ?></strong>
        <p><?= htmlspecialchars(t('partner_payment_secure_text')) ?></p>
    </div>
</div>
<div class="partner-payment-information">
<div><span><?= htmlspecialchars(t('partner_payment_business')) ?></span><strong><?= htmlspecialchars($role_request['business_name']) ?></strong></div>
<div><span><?= htmlspecialchars(t('partner_payment_partner_type')) ?></span><strong><?= htmlspecialchars(paymentRoleName($role_request['requested_role'])) ?></strong></div>
<div><span><?= htmlspecialchars(t('partner_payment_applicant')) ?></span><strong><?= htmlspecialchars($role_request['first_name'].' '.$role_request['last_name']) ?></strong></div>
<div><span><?= htmlspecialchars(t('partner_payment_type')) ?></span><strong><?= htmlspecialchars(t('partner_payment_one_time')) ?></strong></div>
</div>
<div class="partner-payment-total"><span><?= htmlspecialchars(t('partner_payment_total')) ?></span><strong>₪<?= number_format((float)$role_request['payment_amount'],2) ?></strong></div>
</div>
<div class="partner-payment-form-card">
<?php if ($message!==""): ?>
<div class="partner-alert <?= htmlspecialchars($message_type) ?>"><span class="partner-alert-icon"><?= $message_type==='success'?'✓':'!' ?></span><span><?= htmlspecialchars($message) ?></span></div>
<?php endif; ?>
<?php if ($role_request['payment_status']==='paid'): ?>
<div class="partner-payment-success">
<div class="partner-payment-success-icon">✓</div>
<h2><?= htmlspecialchars(t('partner_payment_completed')) ?></h2>
<p><?= htmlspecialchars(t('partner_payment_recorded')) ?></p>
<a href="request_role.php" class="partner-payment-button"><?= htmlspecialchars(t('partner_payment_view_request')) ?></a>
</div>
<?php else: ?>
<div class="partner-payment-heading">
<span><?= htmlspecialchars(t('partner_payment_secure_checkout')) ?></span>
<h2><?= htmlspecialchars(t('partner_payment_details')) ?></h2>
<p><?= htmlspecialchars(t('partner_payment_enter_details')) ?></p>
</div>
<form method="POST" action="partner_payment.php" class="partner-payment-form" autocomplete="off" novalidate>
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
<input type="hidden" name="request_id" value="<?= (int)$request_id ?>">
<div class="partner-payment-field"><label for="cardholder_name"><?= htmlspecialchars(t('partner_payment_cardholder')) ?></label><input type="text" id="cardholder_name" name="cardholder_name" maxlength="100" placeholder="<?= htmlspecialchars(t('partner_payment_full_name')) ?>" required></div>
<div class="partner-payment-field"><label for="card_number"><?= htmlspecialchars(t('partner_payment_card_number')) ?></label><input type="text" id="card_number" name="card_number" maxlength="23" inputmode="numeric" placeholder="4580 0000 0000 0000" required></div>
<div class="partner-payment-row">
<div class="partner-payment-field"><label for="expiry_date"><?= htmlspecialchars(t('partner_payment_expiry')) ?></label><input type="text" id="expiry_date" name="expiry_date" maxlength="5" placeholder="MM/YY" required></div>
<div class="partner-payment-field"><label for="cvv">CVV</label><input type="password" id="cvv" name="cvv" maxlength="4" inputmode="numeric" placeholder="123" required></div>
</div>
<div class="partner-demo-card">
    <strong><?= htmlspecialchars(t('partner_payment_card_information')) ?></strong>
    <span><?= htmlspecialchars(t('partner_payment_card_information_text')) ?></span>
</div>
<button type="submit" class="partner-payment-button">
    <?= htmlspecialchars(t('partner_payment_pay')) ?>
    ₪<?= number_format((float)$role_request['payment_amount'], 2) ?>
</button>
<p class="partner-payment-security">🔒 <?= htmlspecialchars(t('partner_payment_not_saved')) ?></p>
</form>
<?php endif; ?>
</div>
</section>
</main>
<script>
document.addEventListener('DOMContentLoaded',()=>{
const card=document.getElementById('card_number'),expiry=document.getElementById('expiry_date'),cvv=document.getElementById('cvv');
if(card) card.addEventListener('input',function(){let v=this.value.replace(/\D/g,'').substring(0,19);this.value=v.replace(/(.{4})/g,'$1 ').trim()});
if(expiry) expiry.addEventListener('input',function(){let v=this.value.replace(/\D/g,'').substring(0,4);if(v.length>=3)v=v.substring(0,2)+'/'+v.substring(2);this.value=v});
if(cvv) cvv.addEventListener('input',function(){this.value=this.value.replace(/\D/g,'').substring(0,4)});
});
</script>
</body>
</html>
<?php $conn->close(); ?>