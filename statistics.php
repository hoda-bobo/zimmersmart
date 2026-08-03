<?php
// מתחילים Session כדי לזהות את המשתמש המחובר גם לאחר מעבר בין עמודים.
session_start();
// חיבור למסד הנתונים MySQL.
include "connection.php";
// טעינת פונקציות התרגום והגדרת שפת הממשק.
require_once __DIR__ . "/language.php";

// אם אין משתמש מחובר, אין לאפשר גישה לעמוד הסטטיסטיקות.
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$owner_id = (int)$_SESSION['user_id'];
$currentLang = current_language();

// עמוד זה מיועד לבעל צימר בלבד. משתמש מסוג אחר מועבר ל-Dashboard.
if (($_SESSION['user_type'] ?? '') !== 'owner') {
    header("Location: dashboard.php");
    exit();
}

// פונקציית עזר: בודקת אם טבלה קיימת לפני שמריצים עליה שאילתה.
// כך נמנעת שגיאת SQL במקרה שטבלת leads או search_logs עדיין לא נוצרה.
function tableExists($conn, $table){
    $res = $conn->query("SHOW TABLES LIKE '$table'");
    return ($res && $res->num_rows > 0);
}

$has_search_logs = tableExists($conn, "search_logs");
$has_leads = tableExists($conn, "leads");

/* ============================================================
   מדדי KPI מרכזיים של בעל הצימר
   KPI = Key Performance Indicators, כלומר מדדים עסקיים עיקריים.
   כאן מחשבים: הזמנות שהסתיימו, הכנסה נטו, ממוצע הזמנה,
   מספר צימרים, מספר לידים ושיעור המרה.
   ============================================================ */
$total_bookings = 0;
$total_revenue = 0;
$total_cabins = 0;
$avg_booking = 0;
$total_leads = 0;
$conversion_rate = 0;

// השאילתה סופרת רק הזמנות בסטטוס confirmed או completed,
// ורק לאחר שתאריך היציאה עבר. כך הזמנה עתידית אינה מוצגת כהכנסה שהושלמה.
// COALESCE מעדיף את owner_revenue (הכנסה נטו לבעל הצימר),
// ואם הערך חסר משתמש ב-total_price כמספר חלופי.
$q = $conn->query("
SELECT
    COUNT(
        CASE
            WHEN b.status IN ('confirmed', 'completed')
                 AND b.end_date <= CURDATE()
            THEN b.id
        END
    ) AS total_bookings,

    IFNULL(
        SUM(
            CASE
                WHEN b.status IN ('confirmed', 'completed')
                     AND b.end_date <= CURDATE()
                THEN COALESCE(b.owner_revenue, b.total_price)
                ELSE 0
            END
        ),
        0
    ) AS total_revenue,

    IFNULL(
        AVG(
            CASE
                WHEN b.status IN ('confirmed', 'completed')
                     AND b.end_date <= CURDATE()
                THEN COALESCE(b.owner_revenue, b.total_price)
            END
        ),
        0
    ) AS avg_booking

FROM bookings b
JOIN cabins c ON b.cabin_id = c.id
WHERE c.owner_id = $owner_id
");
if($q){
    $k=$q->fetch_assoc();
    $total_bookings=$k['total_bookings'];
    $total_revenue=$k['total_revenue'];
    $avg_booking=$k['avg_booking'];
}

// ספירת כל הצימרים השייכים לבעלים המחובר.
$q = $conn->query("SELECT COUNT(*) total FROM cabins WHERE owner_id=$owner_id");
if($q){ $total_cabins=$q->fetch_assoc()['total']; }

// חישוב מספר הלידים. לידים ללא cabin_id הם לידי חיפוש כלליים,
// ולידים עם cabin_id נספרים רק אם הצימר שייך לבעלים המחובר.
if($has_leads){
    $q = $conn->query("
        SELECT COUNT(*) total
        FROM leads l
        LEFT JOIN cabins c ON l.cabin_id=c.id
        WHERE l.cabin_id IS NULL OR c.owner_id=$owner_id
    ");
    if($q){ $total_leads=$q->fetch_assoc()['total']; }
}

// שיעור ההמרה מחושב כך:
// הזמנות / (הזמנות + לידים) * 100.
// כלומר, מתוך כל הלקוחות הפוטנציאליים, כמה הפכו להזמנה בפועל.
if(($total_bookings + $total_leads) > 0){
    $conversion_rate = round(($total_bookings / ($total_bookings + $total_leads)) * 100, 1);
}

/* ============================================================
   הכנסה ומספר הזמנות לפי חודש
   הקיבוץ נעשה לפי חודש תאריך הכניסה (start_date).
   גם כאן נכללות רק הזמנות confirmed או completed שהשהייה בהן הסתיימה.
   ============================================================ */
$months = [];
$revenues = [];
$bookings = [];

$monthlyRows = [];
$q = $conn->query("
    SELECT
        DATE_FORMAT(b.start_date, '%Y-%m') AS month_name,

        COUNT(
            CASE
                WHEN b.status IN ('confirmed', 'completed')
                     AND b.end_date <= CURDATE()
                THEN b.id
            END
        ) AS bookings_count,

        IFNULL(
            SUM(
                CASE
                    WHEN b.status IN ('confirmed', 'completed')
                         AND b.end_date <= CURDATE()
                    THEN COALESCE(b.owner_revenue, b.total_price)
                    ELSE 0
                END
            ),
            0
        ) AS revenue

    FROM bookings b
    JOIN cabins c ON b.cabin_id = c.id
    WHERE c.owner_id = $owner_id
    GROUP BY DATE_FORMAT(b.start_date, '%Y-%m')
    ORDER BY DATE_FORMAT(b.start_date, '%Y-%m')
");

if (!$q) {
    die('MONTHLY STATISTICS SQL ERROR: ' . $conn->error);
}

while ($r = $q->fetch_assoc()) {
    $monthlyRows[$r['month_name']] = [
        'revenue' => (float)$r['revenue'],
        'bookings' => (int)$r['bookings_count']
    ];
}

/* יצירת ציר חודשים רציף עד החודש הנוכחי.
   אם בחודש מסוים לא היו הזמנות, מוכנס 0 כדי שהגרף לא ידלג עליו. */
if (!empty($monthlyRows)) {
    $firstMonth = new DateTimeImmutable(array_key_first($monthlyRows) . '-01');
    $lastMonth = new DateTimeImmutable(date('Y-m-01'));

    for ($month = $firstMonth; $month <= $lastMonth; $month = $month->modify('+1 month')) {
        $key = $month->format('Y-m');
        $months[] = $key;
        $revenues[] = $monthlyRows[$key]['revenue'] ?? 0;
        $bookings[] = $monthlyRows[$key]['bookings'] ?? 0;
    }
}

/* ============================================================
   הצימרים הפופולריים ביותר
   סופרים כמה הזמנות שהסתיימו יש לכל צימר של הבעלים.
   ============================================================ */
$cabinNames=[]; $cabinBookings=[];
$q=$conn->query("
SELECT c.name,
       COUNT(
           CASE
               WHEN b.status IN ('confirmed', 'completed')
                    AND b.end_date <= CURDATE()
               THEN b.id
           END
       ) total
FROM cabins c
LEFT JOIN bookings b ON c.id = b.cabin_id
WHERE c.owner_id = $owner_id
GROUP BY c.id, c.name
ORDER BY total DESC
LIMIT 6
");
while($q && $r=$q->fetch_assoc()){
    $cabinNames[]=$r['name'];
    $cabinBookings[]=(int)$r['total'];
}

/* ============================================================
   השירותים המבוקשים ביותר בחיפוש
   search_logs שומרת כל שירות שמשתמש סימן בחיפוש.
   כאן סופרים כמה פעמים כל שירות חופש בכלל המערכת.
   ============================================================ */
$serviceSearchNames=[]; $serviceSearchCounts=[];
if($has_search_logs){
$q=$conn->query("
SELECT s.service_name, COUNT(sl.id) searches
FROM services s
LEFT JOIN search_logs sl ON s.service_id=sl.service_id
GROUP BY s.service_id,s.service_name
ORDER BY searches DESC
LIMIT 8
");
while($q && $r=$q->fetch_assoc()){
    $serviceSearchNames[]=$r['service_name'];
    $serviceSearchCounts[]=(int)$r['searches'];
}
}

/* ============================================================
   תרומת שירותים להזמנות
   לכל שירות סופרים כמה הזמנות שהסתיימו בוצעו בצימרים שמציעים אותו.
   חשוב: החישוב אינו אומר שהלקוח רכש את השירות עצמו,
   אלא שהשירות קיים בצימר שקיבל את ההזמנה.
   ============================================================ */
$serviceBookingNames=[]; $serviceBookingCounts=[];
$q=$conn->query("
SELECT s.service_name, COUNT(DISTINCT b.id) total
FROM services s
JOIN cabin_services cs ON s.service_id=cs.service_id
JOIN cabins c ON cs.cabin_id=c.id
LEFT JOIN bookings b ON b.cabin_id = c.id
    AND b.status IN ('confirmed', 'completed')
    AND b.end_date <= CURDATE()
WHERE c.owner_id = $owner_id
GROUP BY s.service_id,s.service_name
ORDER BY total DESC
LIMIT 8
");
while($q && $r=$q->fetch_assoc()){
    $serviceBookingNames[]=$r['service_name'];
    $serviceBookingCounts[]=(int)$r['total'];
}

/* ============================================================
   שיעור המרה לפי שירות
   בודקים האם משתמש שחיפש שירות מסוים ביצע בתוך 30 יום הזמנה
   בצימר של הבעלים שמציע את אותו שירות.
   ============================================================ */
$serviceRows = [];

if ($has_search_logs) {

    $q = $conn->query("
        SELECT
            s.service_id,
            s.service_name,

            COUNT(DISTINCT sl.id) AS searches,

            COUNT(DISTINCT CASE
                WHEN EXISTS (
                    SELECT 1
                    FROM bookings b
                    JOIN cabins c
                        ON c.id = b.cabin_id
                    JOIN cabin_services cs
                        ON cs.cabin_id = c.id
                    WHERE b.user_id = sl.user_id
                      AND c.owner_id = $owner_id
                      AND cs.service_id = s.service_id
                      AND b.created_at >= sl.search_date
                      AND b.created_at < DATE_ADD(sl.search_date, INTERVAL 30 DAY)
                )
                THEN sl.id
            END) AS converted_searches

        FROM services s

        LEFT JOIN search_logs sl
            ON sl.service_id = s.service_id

       GROUP BY s.service_id, s.service_name
HAVING searches >= 3
ORDER BY converted_searches DESC, searches DESC
    ");

    if (!$q) {
        die('SQL error: ' . $conn->error);
    }

while ($r = $q->fetch_assoc()) {

    $searches = (int)$r['searches'];
    $convertedSearches = (int)$r['converted_searches'];

    // לא מציגים שירותים שלא הובילו אפילו להמרה אחת,
// כדי שהטבלה תציג רק נתונים שיש מהם ערך עסקי.
if ($convertedSearches == 0) {
        continue;
    }

    $conversion = $searches > 0
        ? round(($convertedSearches / $searches) * 100, 1)
        : 0;

    $serviceRows[] = [
        'name' => $r['service_name'],
        'searches' => $searches,
        'bookings' => $convertedSearches,
        'conversion' => $conversion
    ];
}
}
/* ============================================================
   ניתוח לידים
   שלושה שלבים: חיפוש ללא הזמנה, צפייה ללא הזמנה,
   והתחלת תשלום ללא השלמת הזמנה.
   ============================================================ */
$leadTypes=[]; 
$leadCounts=[];
$topLeadCabins=[];
$recentLeads=[];

$search_leads=0;
$view_leads=0;
$payment_leads=0;

if($has_leads){

    $q=$conn->query("
        SELECT l.lead_type, COUNT(*) total
        FROM leads l
        LEFT JOIN cabins c ON l.cabin_id=c.id
        WHERE l.cabin_id IS NULL OR c.owner_id=$owner_id
        GROUP BY l.lead_type
    ");
    while($q && $r=$q->fetch_assoc()){
        $leadTypes[]=$r['lead_type'];
        $leadCounts[]=(int)$r['total'];

        if($r['lead_type']=="searched_no_booking") $search_leads=(int)$r['total'];
        if($r['lead_type']=="viewed_no_booking") $view_leads=(int)$r['total'];
        if($r['lead_type']=="payment_started_no_booking") $payment_leads=(int)$r['total'];
    }

    $q=$conn->query("
        SELECT c.name, COUNT(l.id) total
        FROM leads l
        JOIN cabins c ON l.cabin_id=c.id
        WHERE c.owner_id=$owner_id
        GROUP BY c.id,c.name
        ORDER BY total DESC
        LIMIT 8
    ");
    while($q && $r=$q->fetch_assoc()){
        $topLeadCabins[]=$r;
    }

    $q=$conn->query("
        SELECT 
        u.first_name,
        u.last_name,
        c.name cabin_name,
        l.lead_type,
        l.notes,
        l.created_at
        FROM leads l
        JOIN users u ON l.user_id=u.id
        LEFT JOIN cabins c ON l.cabin_id=c.id
        WHERE l.cabin_id IS NULL OR c.owner_id=$owner_id
        ORDER BY l.created_at DESC
        LIMIT 15
    ");
    while($q && $r=$q->fetch_assoc()){
        $recentLeads[]=$r;
    }
}

// משפך המכירות מציג את מסע הלקוח מחיפוש ועד הזמנה.
$funnelLabels = [t("searches"), t("views"), t("payment_started"), t("bookings")];
$funnelValues = [$search_leads, $view_leads, $payment_leads, (int)$total_bookings];

// איתור החודש שבו ההכנסה הייתה הגבוהה ביותר.
$best_month = "-";
$best_revenue = 0;
for($i=0;$i<count($months);$i++){
    if($revenues[$i] > $best_revenue){
        $best_revenue = $revenues[$i];
        $best_month = date('m/Y', strtotime($months[$i] . '-01'));
    }
}


// המרת הערך הטכני של סוג הליד לטקסט מתורגם שיוצג למשתמש.
function translatedLeadType($type){
    $map = [
        "searched_no_booking" => "searched_no_booking",
        "viewed_no_booking" => "viewed_no_booking",
        "payment_started_no_booking" => "payment_started_no_booking"
    ];
    return isset($map[$type]) ? t($map[$type]) : $type;
}

$top_cabin_name = !empty($cabinNames) ? $cabinNames[0] : '-';
$top_cabin_bookings = !empty($cabinBookings) ? (int)$cabinBookings[0] : 0;
$top_service_name = !empty($serviceSearchNames) ? $serviceSearchNames[0] : '-';
$top_service_searches = !empty($serviceSearchCounts) ? (int)$serviceSearchCounts[0] : 0;

$last_nonzero_revenue = 0;
$previous_nonzero_revenue = 0;
for ($i = count($revenues) - 1; $i >= 0; $i--) {
    if ($revenues[$i] > 0) {
        if ($last_nonzero_revenue == 0) {
            $last_nonzero_revenue = $revenues[$i];
        } else {
            $previous_nonzero_revenue = $revenues[$i];
            break;
        }
    }
}
// אחוז השינוי בין שני החודשים האחרונים שבהם הייתה הכנסה בפועל.
// מדלגים על חודשים עם אפס כדי להשוות בין חודשי פעילות עסקית.
$revenue_change = $previous_nonzero_revenue > 0
    ? round((($last_nonzero_revenue - $previous_nonzero_revenue) / $previous_nonzero_revenue) * 100, 1)
    : 0;

$he = $currentLang === 'he';
?>

<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang) ?>" dir="<?= $he ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(t('statistics_dashboard')) ?> - <?= htmlspecialchars(t('site_name')) ?></title>
<link rel="stylesheet" href="style.css?v=<?= time() ?>">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
.stats-page.stats-premium{padding:42px 0 90px;background:linear-gradient(180deg,#eef5fa 0,#f8fbfd 42%,#eef5fa 100%)}
.stats-premium .stats-container{width:min(1480px,calc(100% - 44px));margin:auto}
.stats-premium .stats-hero{display:grid;grid-template-columns:minmax(0,1fr) 260px;gap:26px;align-items:center;padding:44px 48px;margin-bottom:26px;border-radius:28px;background:linear-gradient(135deg,#132d4f,#315f82);color:#fff;box-shadow:0 22px 55px rgba(20,48,80,.18)}
.stats-premium .stats-hero h1{margin:8px 0 12px;font-size:clamp(38px,4.4vw,62px);line-height:1.08;color:#fff}
.stats-premium .stats-hero p{max-width:850px;margin:0;font-size:20px;line-height:1.75;color:#eef6fb}
.stats-premium .mini-title{font-size:16px;letter-spacing:.3px;color:#d7eaf7}
.stats-premium .hero-box{padding:24px;border:1px solid rgba(255,255,255,.24);border-radius:20px;background:rgba(255,255,255,.11);text-align:center}
.stats-premium .hero-box span,.stats-premium .hero-box small{display:block;font-size:16px;color:#eef6fb}.stats-premium .hero-box strong{display:block;margin:8px 0;font-size:50px}
.stats-premium .kpi-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:16px;margin-bottom:24px}
.stats-premium .kpi-card{min-height:185px;padding:22px;display:flex;flex-direction:column;justify-content:space-between;border:1px solid #c8d9e6;border-radius:20px;background:#fff;box-shadow:0 12px 28px rgba(28,64,96,.08)}
.stats-premium .kpi-card span{font-size:17px;font-weight:800;color:#355d7d}.stats-premium .kpi-card h2{margin:14px 0;font-size:35px;line-height:1.1;color:#183153}.stats-premium .kpi-card p{margin:0;font-size:15px;line-height:1.5;color:#68849a}
.stats-premium .quick-insights{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:26px}
.stats-premium .quick-card{padding:22px;border-radius:18px;background:#fff;border:1px solid #c8d9e6;box-shadow:0 10px 24px rgba(28,64,96,.07)}
.stats-premium .quick-card small{display:block;font-size:15px;color:#7895ad}.stats-premium .quick-card strong{display:block;margin-top:7px;font-size:23px;color:#183153}
.stats-premium .section-heading{margin:38px 0 18px}.stats-premium .section-heading h2{margin:0 0 6px;font-size:34px}.stats-premium .section-heading p{margin:0;font-size:18px;color:#58758d}
.stats-premium .charts-grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:22px}
.stats-premium .chart-card{grid-column:span 6;padding:26px;border:1px solid #c7d9e6;border-radius:22px;background:#fff;box-shadow:0 13px 32px rgba(28,64,96,.09)}
.stats-premium .chart-card.wide{grid-column:span 12}.stats-premium .chart-card.third{grid-column:span 4}
.stats-premium .chart-head{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;margin-bottom:16px}.stats-premium .chart-head h3{margin:0;font-size:27px;line-height:1.25}.stats-premium .chart-chip{padding:7px 11px;border-radius:999px;background:#e8f0f6;font-size:14px;font-weight:800;color:#355d7d;white-space:nowrap}
.stats-premium .chart-wrap{position:relative;height:350px}.stats-premium .chart-card.wide .chart-wrap{height:390px}.stats-premium .chart-card.third .chart-wrap{height:315px}
.stats-premium canvas{display:block!important;width:100%!important;height:100%!important;max-height:none!important}
.stats-premium .chart-explanation{margin:18px 0 0;padding:15px 17px;border-inline-start:4px solid #355d7d;border-radius:10px;background:#f3f7fa;color:#355d7d;font-size:16px;line-height:1.7}
.stats-premium .empty-msg{min-height:260px;display:grid;place-items:center;margin:0;border:1px dashed #9eb6c8;border-radius:16px;background:#f8fbfd;font-size:18px;color:#68849a}
.stats-premium .table-card{margin-top:24px;padding:28px;border:1px solid #c7d9e6;border-radius:22px;background:#fff;box-shadow:0 13px 32px rgba(28,64,96,.09)}
.stats-premium .table-card h3{margin:0 0 8px;font-size:30px}.stats-premium .table-note{margin:0 0 22px;font-size:18px;line-height:1.7;color:#58758d}
.stats-premium .table-responsive{overflow:auto;border:1px solid #d2e0ea;border-radius:16px}.stats-premium .stats-table{width:100%;border-collapse:collapse;min-width:820px}.stats-premium .stats-table th{padding:18px 20px;background:#e8f0f6;font-size:19px;text-align:start}.stats-premium .stats-table td{padding:18px 20px;border-top:1px solid #e3edf4;font-size:18px;line-height:1.55;vertical-align:middle}.stats-premium .stats-table tr:hover td{background:#f8fbfd}
.stats-premium .progress-wrap{display:inline-block;width:180px;height:12px;margin-inline-end:12px;overflow:hidden;border-radius:999px;background:#dce8f1}.stats-premium .progress-bar{height:100%;border-radius:999px;background:linear-gradient(90deg,#355d7d,#78a9c8)}
.stats-premium .lead-badge{font-size:15px;padding:8px 12px}
@media(max-width:1200px){.stats-premium .kpi-grid{grid-template-columns:repeat(3,1fr)}.stats-premium .quick-insights{grid-template-columns:repeat(2,1fr)}.stats-premium .chart-card.third{grid-column:span 6}}
@media(max-width:780px){.stats-premium .stats-container{width:min(100% - 24px,1480px)}.stats-premium .stats-hero{grid-template-columns:1fr;padding:30px 24px}.stats-premium .kpi-grid,.stats-premium .quick-insights{grid-template-columns:1fr}.stats-premium .chart-card,.stats-premium .chart-card.third,.stats-premium .chart-card.wide{grid-column:span 12}.stats-premium .chart-wrap{height:300px!important}.stats-premium .chart-head{flex-direction:column}.stats-premium .stats-table th{font-size:17px}.stats-premium .stats-table td{font-size:16px}}
</style>
</head>
<body>
<?php include "navbar.php"; ?>
<main class="stats-page stats-premium">
<div class="stats-container">
<section class="stats-hero">
<div><span class="mini-title"><?= htmlspecialchars(t('owner_business_intelligence')) ?></span><h1><?= htmlspecialchars(t('statistics_dashboard')) ?></h1><p><?= htmlspecialchars(t('statistics_full_description')) ?></p></div>
<div class="hero-box"><span><?= htmlspecialchars(t('conversion_rate')) ?></span><strong><?= $conversion_rate ?>%</strong><small><?= htmlspecialchars(t('bookings_from_potential_customers')) ?></small></div>
</section>

<section class="kpi-grid">
<div class="kpi-card"><span><?= htmlspecialchars(t('total_revenue')) ?></span><h2>₪<?= number_format($total_revenue,0) ?></h2><p><?= $he ? 'הכנסה נטו מהזמנות שהסתיימו' : 'Net income from completed stays' ?></p></div>
<div class="kpi-card"><span><?= htmlspecialchars(t('total_bookings')) ?></span><h2><?= $total_bookings ?></h2><p><?= $he ? 'הזמנות מאושרות שהסתיימו' : 'Finished confirmed bookings' ?></p></div>
<div class="kpi-card"><span><?= htmlspecialchars(t('average_booking')) ?></span><h2>₪<?= number_format($avg_booking,0) ?></h2><p><?= htmlspecialchars(t('average_order_value')) ?></p></div>
<div class="kpi-card"><span><?= htmlspecialchars(t('active_cabins')) ?></span><h2><?= $total_cabins ?></h2><p><?= htmlspecialchars(t('your_cabins')) ?></p></div>
<div class="kpi-card"><span><?= htmlspecialchars(t('total_leads')) ?></span><h2><?= $total_leads ?></h2><p><?= htmlspecialchars(t('potential_customers')) ?></p></div>
<div class="kpi-card"><span><?= htmlspecialchars(t('best_month')) ?></span><h2><?= htmlspecialchars($best_month) ?></h2><p>₪<?= number_format($best_revenue,0) ?></p></div>
</section>

<section class="quick-insights">
<div class="quick-card"><small><?= $he ? 'הצימר המוביל' : 'Top cabin' ?></small><strong><?= htmlspecialchars($top_cabin_name) ?> · <?= $top_cabin_bookings ?></strong></div>
<div class="quick-card"><small><?= $he ? 'השירות המבוקש בחיפוש' : 'Most searched service' ?></small><strong><?= htmlspecialchars($top_service_name) ?> · <?= $top_service_searches ?></strong></div>
<div class="quick-card"><small><?= $he ? 'שינוי לעומת חודש הכנסה קודם' : 'Change from previous revenue month' ?></small><strong><?= $revenue_change > 0 ? '+' : '' ?><?= $revenue_change ?>%</strong></div>
<div class="quick-card"><small><?= $he ? 'יחס הזמנות ללידים' : 'Bookings to leads' ?></small><strong><?= $conversion_rate ?>%</strong></div>
</section>

<div class="section-heading"><h2><?= $he ? 'מגמות עסקיות לאורך זמן' : 'Business trends over time' ?></h2><p><?= $he ? 'הגרפים הבאים מציגים רק הזמנות מאושרות או שהושלמו ושמועד היציאה שלהן כבר עבר.' : 'The following charts include confirmed or completed bookings whose checkout date has passed.' ?></p></div>
<section class="charts-grid">
<article class="chart-card"><div class="chart-head"><h3><?= htmlspecialchars(t('revenue_per_month')) ?></h3><span class="chart-chip">₪ <?= $he ? 'נטו לבעל הצימר' : 'owner net' ?></span></div><?php if(array_sum($revenues)>0): ?><div class="chart-wrap"><canvas id="revenueChart"></canvas></div><p class="chart-explanation"><?= $he ? 'כל נקודה מייצגת את ההכנסה החודשית נטו לבעל הצימר. עלייה בקו מצביעה על חודש חזק יותר; ירידה מצביעה על צורך בבדיקת מחיר, תפוסה או שיווק.' : 'Each point is monthly net owner revenue. Rising lines indicate stronger months.' ?></p><?php else: ?><p class="empty-msg"><?= $he?'אין הכנסות שהסתיימו להצגה':'No completed revenue data' ?></p><?php endif; ?></article>
<article class="chart-card"><div class="chart-head"><h3><?= htmlspecialchars(t('bookings_per_month')) ?></h3><span class="chart-chip"><?= $he ? 'מספר שהיות' : 'completed stays' ?></span></div><?php if(array_sum($bookings)>0): ?><div class="chart-wrap"><canvas id="bookingChart"></canvas></div><p class="chart-explanation"><?= $he ? 'גובה כל עמודה הוא מספר ההזמנות שהסתיימו באותו חודש. השוואה לגרף ההכנסות עוזרת לזהות האם חודש חזק נבע מכמות הזמנות או מהזמנות יקרות יותר.' : 'Each bar is the number of completed stays that month.' ?></p><?php else: ?><p class="empty-msg"><?= $he?'אין הזמנות שהסתיימו להצגה':'No completed booking data' ?></p><?php endif; ?></article>
<article class="chart-card third"><div class="chart-head"><h3><?= htmlspecialchars(t('most_popular_cabins')) ?></h3><span class="chart-chip"><?= $he?'לפי הזמנות':'by bookings' ?></span></div><div class="chart-wrap"><canvas id="popularCabinsChart"></canvas></div><p class="chart-explanation"><?= $he ? 'התרשים משווה בין הצימרים לפי מספר ההזמנות שהסתיימו. נתח גדול יותר מצביע על ביקוש גבוה יותר.' : 'Larger segments indicate stronger cabin demand.' ?></p></article>
<article class="chart-card third"><div class="chart-head"><h3><?= htmlspecialchars(t('most_searched_services')) ?></h3><span class="chart-chip"><?= $he?'עניין לקוחות':'customer interest' ?></span></div><?php if($has_search_logs && array_sum($serviceSearchCounts)>0): ?><div class="chart-wrap"><canvas id="serviceSearchChart"></canvas></div><p class="chart-explanation"><?= $he ? 'מציג אילו שירותים הלקוחות מסננים ומחפשים הכי הרבה. זהו כלי לתכנון השקעות ושדרוגים בצימרים.' : 'Shows what guests search for most.' ?></p><?php else: ?><p class="empty-msg"><?= $he?'אין נתוני חיפוש':'No search data' ?></p><?php endif; ?></article>
<article class="chart-card third"><div class="chart-head"><h3><?= htmlspecialchars(t('lead_types')) ?></h3><span class="chart-chip"><?= $he?'נקודות נטישה':'drop-off points' ?></span></div><?php if($has_leads && array_sum($leadCounts)>0): ?><div class="chart-wrap"><canvas id="leadTypesChart"></canvas></div><p class="chart-explanation"><?= $he ? 'התרשים מראה באיזה שלב לקוחות עזבו: לאחר חיפוש, צפייה בצימר או התחלת תשלום. שלב גדול במיוחד הוא המקום הראשון שכדאי לשפר.' : 'Shows where potential guests leave the funnel.' ?></p><?php else: ?><p class="empty-msg"><?= $he?'אין נתוני לידים':'No lead data' ?></p><?php endif; ?></article>
<article class="chart-card wide"><div class="chart-head"><h3><?= htmlspecialchars(t('services_contributed_to_bookings')) ?></h3><span class="chart-chip"><?= $he?'השפעה על הזמנות':'booking impact' ?></span></div><div class="chart-wrap"><canvas id="serviceBookingChart"></canvas></div><p class="chart-explanation"><?= $he ? 'מספר ההזמנות של צימרים שמציעים כל שירות. שירות עם עמודה גבוהה מופיע בצימרים שמייצרים יותר הזמנות, ולכן עשוי להיות גורם תחרותי חשוב.' : 'Shows how often each amenity appears in cabins that generated bookings.' ?></p></article>
<article class="chart-card wide"><div class="chart-head"><h3><?= htmlspecialchars(t('sales_funnel')) ?></h3><span class="chart-chip"><?= $he?'מסע הלקוח':'customer journey' ?></span></div><?php if($has_leads): ?><div class="chart-wrap"><canvas id="funnelChart"></canvas></div><p class="chart-explanation"><?= $he ? 'המשפך מציג את המעבר מחיפוש להזמנה. הפער הגדול ביותר בין שני שלבים מצביע על נקודת החיכוך המרכזית במערכת.' : 'The largest drop between stages highlights the main friction point.' ?></p><?php else: ?><p class="empty-msg"><?= $he?'אין נתוני לידים':'No lead data' ?></p><?php endif; ?></article>
</section>

<div class="section-heading"><h2><?= $he?'נתונים מפורטים והמלצות פעולה':'Detailed data and actions' ?></h2><p><?= $he?'הטבלאות מאפשרות להבין לא רק מה קרה, אלא גם באיזה שירות או צימר כדאי להתמקד.':'Tables help turn results into concrete actions.' ?></p></div>
<section class="table-card"><h3><?= htmlspecialchars(t('services_conversion_rate')) ?></h3><p class="table-note"><?= htmlspecialchars(t('services_conversion_explanation')) ?></p><div class="table-responsive"><table class="stats-table"><thead><tr><th><?= htmlspecialchars(t('service')) ?></th><th><?= htmlspecialchars(t('searches')) ?></th><th><?= htmlspecialchars(t('bookings')) ?></th><th><?= htmlspecialchars(t('conversion')) ?></th></tr></thead><tbody><?php if(empty($serviceRows)): ?><tr><td colspan="4"><?= $he?'אין עדיין מספיק חיפושים שהומרו להזמנה.':'Not enough converted searches yet.' ?></td></tr><?php else: foreach($serviceRows as $row): ?><tr><td><strong><?= htmlspecialchars($row['name']) ?></strong></td><td><?= $row['searches'] ?></td><td><?= $row['bookings'] ?></td><td><div class="progress-wrap"><div class="progress-bar" style="width:<?= min($row['conversion'],100) ?>%"></div></div><b><?= $row['conversion'] ?>%</b></td></tr><?php endforeach; endif; ?></tbody></table></div></section>
<?php if($has_leads): ?>
<section class="table-card"><h3><?= htmlspecialchars(t('top_cabins_by_leads')) ?></h3><p class="table-note"><?= htmlspecialchars(t('top_cabins_by_leads_explanation')) ?></p><div class="table-responsive"><table class="stats-table"><thead><tr><th><?= htmlspecialchars(t('cabin')) ?></th><th><?= htmlspecialchars(t('total_leads')) ?></th><th><?= htmlspecialchars(t('meaning')) ?></th></tr></thead><tbody><?php foreach($topLeadCabins as $row): ?><tr><td><strong><?= htmlspecialchars($row['name']) ?></strong></td><td><?= $row['total'] ?></td><td><?= htmlspecialchars(t('high_interest_recommendation')) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="table-card"><h3><?= htmlspecialchars(t('recent_leads')) ?></h3><p class="table-note"><?= htmlspecialchars(t('recent_leads_explanation')) ?></p><div class="table-responsive"><table class="stats-table"><thead><tr><th><?= htmlspecialchars(t('user')) ?></th><th><?= htmlspecialchars(t('cabin')) ?></th><th><?= htmlspecialchars(t('lead_type')) ?></th><th><?= htmlspecialchars(t('date')) ?></th><th><?= htmlspecialchars(t('notes')) ?></th></tr></thead><tbody><?php foreach($recentLeads as $row): ?><tr><td><?= htmlspecialchars($row['first_name'].' '.$row['last_name']) ?></td><td><?= htmlspecialchars($row['cabin_name'] ?? t('search_page')) ?></td><td><span class="lead-badge"><?= htmlspecialchars(translatedLeadType($row['lead_type'])) ?></span></td><td><?= htmlspecialchars(date('d/m/Y H:i',strtotime($row['created_at']))) ?></td><td>
    <?= htmlspecialchars(t($row['notes'] ?? '')) ?>
</td></tr><?php endforeach; ?></tbody></table></div></section>
<?php endif; ?>
</div>
</main>
<script>
// העברת המערכים שחושבו ב-PHP אל JavaScript לצורך בניית הגרפים ב-Chart.js.
const months=<?= json_encode($months,JSON_UNESCAPED_UNICODE) ?>,revenues=<?= json_encode($revenues) ?>,bookings=<?= json_encode($bookings) ?>;
const cabinNames=<?= json_encode($cabinNames,JSON_UNESCAPED_UNICODE) ?>,cabinBookings=<?= json_encode($cabinBookings) ?>;
const serviceSearchNames=<?= json_encode($serviceSearchNames,JSON_UNESCAPED_UNICODE) ?>,serviceSearchCounts=<?= json_encode($serviceSearchCounts) ?>;
const serviceBookingNames=<?= json_encode($serviceBookingNames,JSON_UNESCAPED_UNICODE) ?>,serviceBookingCounts=<?= json_encode($serviceBookingCounts) ?>;
const leadTypes=<?= json_encode(array_map('translatedLeadType',$leadTypes),JSON_UNESCAPED_UNICODE) ?>,leadCounts=<?= json_encode($leadCounts) ?>;
const funnelLabels=<?= json_encode($funnelLabels,JSON_UNESCAPED_UNICODE) ?>,funnelValues=<?= json_encode($funnelValues) ?>;
Chart.defaults.font.family='Arial, Segoe UI, sans-serif';Chart.defaults.font.size=14;Chart.defaults.color='#355d7d';
const gridColor='rgba(120,149,173,.20)',navy='#183153',blue='#3c83b5',light='#8cc8ec';
const common={responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},plugins:{legend:{labels:{usePointStyle:true,padding:18,font:{size:14,weight:'600'}}},tooltip:{padding:13,titleFont:{size:15},bodyFont:{size:14},backgroundColor:'rgba(24,49,83,.94)'}},scales:{x:{grid:{display:false},ticks:{maxRotation:45,minRotation:0}},y:{beginAtZero:true,grid:{color:gridColor}}}};
function money(v){return new Intl.NumberFormat('he-IL',{style:'currency',currency:'ILS',maximumFractionDigits:0}).format(v)}
if(document.getElementById('revenueChart'))new Chart(document.getElementById('revenueChart'),{type:'line',data:{labels:months,datasets:[{label:<?= json_encode($he?'הכנסה נטו':'Net revenue',JSON_UNESCAPED_UNICODE) ?>,data:revenues,borderColor:blue,backgroundColor:'rgba(60,131,181,.20)',pointBackgroundColor:'#fff',pointBorderColor:blue,pointBorderWidth:3,pointRadius:5,pointHoverRadius:8,borderWidth:4,tension:.35,fill:true}]},options:{...common,plugins:{...common.plugins,tooltip:{...common.plugins.tooltip,callbacks:{label:c=>' '+money(c.parsed.y)}}},scales:{...common.scales,y:{...common.scales.y,ticks:{callback:v=>money(v)}}}}});
if(document.getElementById('bookingChart'))new Chart(document.getElementById('bookingChart'),{type:'bar',data:{labels:months,datasets:[{label:<?= json_encode($he?'הזמנות שהסתיימו':'Completed bookings',JSON_UNESCAPED_UNICODE) ?>,data:bookings,backgroundColor:'rgba(60,131,181,.76)',borderColor:blue,borderWidth:1,borderRadius:9,maxBarThickness:42}]},options:{...common,scales:{...common.scales,y:{...common.scales.y,ticks:{precision:0,stepSize:1}}}}});
if(document.getElementById('popularCabinsChart'))new Chart(document.getElementById('popularCabinsChart'),{type:'doughnut',data:{labels:cabinNames,datasets:[{data:cabinBookings,backgroundColor:['#183153','#355d7d','#5f88a6','#8cb1c9','#b3cfdf','#d7e7f0'],borderWidth:3,borderColor:'#fff',hoverOffset:10}]},options:{responsive:true,maintainAspectRatio:false,cutout:'62%',plugins:{legend:{position:'bottom',labels:{usePointStyle:true,padding:13,font:{size:13}}},tooltip:common.plugins.tooltip}}});
if(document.getElementById('serviceSearchChart'))new Chart(document.getElementById('serviceSearchChart'),{type:'bar',data:{labels:serviceSearchNames,datasets:[{label:<?= json_encode($he?'חיפושים':'Searches',JSON_UNESCAPED_UNICODE) ?>,data:serviceSearchCounts,backgroundColor:'rgba(140,200,236,.85)',borderColor:blue,borderWidth:1,borderRadius:8}]},options:{...common,indexAxis:'y',scales:{x:{beginAtZero:true,grid:{color:gridColor},ticks:{precision:0}},y:{grid:{display:false}}}}});
if(document.getElementById('leadTypesChart'))new Chart(document.getElementById('leadTypesChart'),{type:'polarArea',data:{labels:leadTypes,datasets:[{data:leadCounts,backgroundColor:['rgba(24,49,83,.82)','rgba(53,93,125,.76)','rgba(140,200,236,.82)'],borderColor:'#fff',borderWidth:3}]},options:{responsive:true,maintainAspectRatio:false,scales:{r:{beginAtZero:true,ticks:{precision:0,backdropColor:'transparent'},grid:{color:gridColor}}},plugins:{legend:{position:'bottom',labels:{usePointStyle:true,padding:12}}}}});
if(document.getElementById('serviceBookingChart'))new Chart(document.getElementById('serviceBookingChart'),{type:'bar',data:{labels:serviceBookingNames,datasets:[{label:<?= json_encode($he?'הזמנות':'Bookings',JSON_UNESCAPED_UNICODE) ?>,data:serviceBookingCounts,backgroundColor:serviceBookingCounts.map((_,i)=>`rgba(53,93,125,${Math.max(.38,.92-i*.07)})`),borderRadius:9,maxBarThickness:58}]},options:{...common,scales:{...common.scales,y:{...common.scales.y,ticks:{precision:0}}}}});
if(document.getElementById('funnelChart'))new Chart(document.getElementById('funnelChart'),{type:'bar',data:{labels:funnelLabels,datasets:[{label:<?= json_encode($he?'לקוחות בכל שלב':'Customers per stage',JSON_UNESCAPED_UNICODE) ?>,data:funnelValues,backgroundColor:['#9ccce8','#6fa8ca','#3f7da5','#183153'],borderRadius:12,maxBarThickness:110}]},options:{...common,plugins:{...common.plugins,legend:{display:false}},scales:{...common.scales,y:{...common.scales.y,ticks:{precision:0}}}}});
</script>
</body>
</html>
