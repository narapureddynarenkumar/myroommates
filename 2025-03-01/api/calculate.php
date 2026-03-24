<?php
require "../config/db.php";
require "../utils/response.php";

// -----------------------------
// 1️⃣ Get Inputs
// -----------------------------
$room_id = isset($_GET['room_id']) ? intval($_GET['room_id']) : 0;
$month   = isset($_GET['month']) ? intval($_GET['month']) : date('n');
$year    = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

if (!$room_id) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'room_id required']);
    exit;
}

// -----------------------------
// 2️⃣ Compute month start/end
// -----------------------------
$start_date = date("Y-m-01", strtotime("$year-$month-01"));
$end_date   = date("Y-m-t", strtotime($start_date));
$total_days = (int)date("t", strtotime($start_date));

// -----------------------------
// 3️⃣ Connect DB
// -----------------------------
$db = (new Database())->connect();
$db->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
$db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false); // ensure numeric fetch
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);


$snapQuery = $db->prepare("
    SELECT * FROM calculation_snapshots
    WHERE room_id=? AND month=? AND year=? AND frozen=1
");
$snapQuery->execute([$room_id, $month, $year]);
$snapshot = $snapQuery->fetch(PDO::FETCH_ASSOC);

if ($snapshot) {

    // Return stored shares
    $shareQuery = $db->prepare("
        SELECT m.name AS name, share_amount AS owed
        FROM calculation_member_shares ms JOIN room_members m
        ON ms.member_id = m.id
        WHERE snapshot_id=?
    ");
    $shareQuery->execute([$snapshot['id']]);
    $shares = $shareQuery->fetchAll(PDO::FETCH_ASSOC);

    // Return stored settlements
    $setQuery = $db->prepare("
        SELECT m1.name AS from_member,
                m2.name AS to_member, amount, status
        FROM calculation_settlements s
        JOIN room_members m1 ON s.from_member = m1.id
        JOIN room_members m2 ON s.to_member = m2.id
        WHERE snapshot_id=?
    ");
    $setQuery->execute([$snapshot['id']]);
    $settlements = $setQuery->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "frozen" => true,
        "total" => $snapshot['total_amount'],
        "shares" => $shares,
        "settlements" => $settlements
    ]);
    exit;
}
// -----------------------------
// 4️⃣ Get total month expenses
// -----------------------------
$stmt_total = $db->prepare("
    SELECT SUM(amount) AS total_amount
    FROM expenses
    WHERE room_id = :room_id
      AND is_deleted = 0
      AND expense_date BETWEEN :start_date AND :end_date
");
$stmt_total->execute([
    ':room_id' => $room_id,
    ':start_date' => $start_date,
    ':end_date' => $end_date
]);
$total_amount = (float) $stmt_total->fetchColumn();

// -----------------------------
// 5️⃣ Optimized SQL for members, shares, paid & balances
// -----------------------------
$sql = "
WITH member_rules AS (
    SELECT
        rm.id AS member_id,
        rm.name,
        COALESCE(mr.excluded,0) AS excluded,
        GREATEST(COALESCE(mr.start_date, :start_date), :start_date) AS start_date,
        LEAST(COALESCE(rm.left_at, mr.end_date, :end_date), :end_date) AS end_date,
        rm.is_active,
        mr.start_date AS s_date,
        mr.end_date AS e_date
    FROM room_members rm
    LEFT JOIN member_month_rules mr
        ON rm.id = mr.room_member_id
        AND mr.month = :month
        AND mr.year = :year
    WHERE rm.room_id = :room_id
),
member_days AS (
    SELECT
        member_id,
        name,
        excluded,
        start_date,
        end_date,
        CASE
            WHEN excluded = 1 THEN 0
            WHEN end_date < start_date THEN 0
            ELSE DATEDIFF(end_date,start_date)+1
        END AS days_present,
        is_active,
        s_date,
        e_date
    FROM member_rules
),
category_exclusions AS (
    SELECT
        mr.room_member_id AS member_id,
        CONCAT('[', GROUP_CONCAT(rc.id), ']') AS excluded_category_ids,
        GROUP_CONCAT(rc.name) AS excluded_category_names
    FROM member_month_rules mr
    JOIN member_month_category_exclusions ce ON ce.rule_id = mr.id
    JOIN room_categories rc ON rc.id = ce.category_id
    WHERE mr.month = :month AND mr.year = :year
    GROUP BY mr.room_member_id
),
monthly_expenses AS (
    SELECT id AS expense_id, amount, category_id, expense_date, paid_by
    FROM expenses
    WHERE room_id = :room_id AND is_deleted = 0 AND expense_date BETWEEN :start_date AND :end_date
),
eligible_members AS (
    SELECT e.expense_id, e.amount, e.category_id, m.member_id, m.days_present
    FROM monthly_expenses e
    JOIN member_days m ON e.expense_date BETWEEN m.start_date AND m.end_date AND m.excluded = 0
    WHERE NOT EXISTS (
        SELECT 1
        FROM member_month_rules mr
        JOIN member_month_category_exclusions ce ON ce.rule_id = mr.id
        WHERE mr.room_member_id = m.member_id
        AND mr.month = :month
        AND mr.year = :year
        AND ce.category_id = e.category_id
    )
),
expense_days AS (
    SELECT expense_id, SUM(days_present) AS total_days
    FROM eligible_members
    GROUP BY expense_id
),
expense_split AS (
    SELECT em.member_id,
           ROUND(SUM(em.amount * (em.days_present / ed.total_days)),2) AS share
    FROM eligible_members em
    JOIN expense_days ed ON em.expense_id = ed.expense_id
    GROUP BY em.member_id
),
paid_amounts AS (
    SELECT paid_by AS member_id, SUM(amount) AS paid
    FROM expenses
    WHERE room_id = :room_id AND is_deleted = 0 AND expense_date BETWEEN :start_date AND :end_date
    GROUP BY paid_by
),
balances AS (
    SELECT
        m.member_id,
        m.name,
        COALESCE(es.share,0) AS owed,
        COALESCE(p.paid,0) AS paid,
        ROUND(COALESCE(p.paid,0) - COALESCE(es.share,0),2) AS balance
    FROM member_days m
    LEFT JOIN expense_split es ON es.member_id = m.member_id
    LEFT JOIN paid_amounts p ON p.member_id = m.member_id
)
SELECT
    b.member_id,
    b.name,
    b.paid,
    b.owed,
    b.balance,
    COALESCE(c.excluded_category_ids,'[]') AS excluded_category_ids,
    COALESCE(c.excluded_category_names,'All Categories') AS excluded_category_names,
    CASE
        WHEN m.excluded = 1
        THEN CONCAT('Excluded for ', DATE_FORMAT(:start_date,'%M'))
        WHEN m.days_present = :total_days
        -- THEN CONCAT('Full month • ', COALESCE(c.excluded_category_names,'All Categories'))
        THEN CONCAT(
    'Full month • ',
    CASE
        WHEN c.excluded_category_names IS NULL
            THEN 'All Categories'
        WHEN (LENGTH(c.excluded_category_names) - LENGTH(REPLACE(c.excluded_category_names, ',', '')) + 1) > 2
            THEN CONCAT(
                SUBSTRING_INDEX(c.excluded_category_names, ',', 2),
                ' +',
                (LENGTH(c.excluded_category_names) - LENGTH(REPLACE(c.excluded_category_names, ',', '')) - 1)
                    )
                ELSE c.excluded_category_names
            END
        )
        ELSE CONCAT(m.days_present,'/',:total_days,' Days • ', COALESCE(c.excluded_category_names,'All Categories'))
    END AS tooltip,
    m.days_present,
    CASE
        WHEN (m.is_active = 0 OR m.s_date IS NOT NULL)
        THEN m.start_date
        ELSE NULL
        END AS start_date,
    CASE
        WHEN (m.is_active = 0 OR m.e_date IS NOT NULL)
        THEN m.end_date
        ELSE NULL
    END AS end_date,
    m.excluded
FROM balances b
JOIN member_days m ON m.member_id = b.member_id
LEFT JOIN category_exclusions c ON c.member_id = b.member_id
";

// -----------------------------
// 6️⃣ Execute SQL
// -----------------------------
$stmt = $db->prepare($sql);
$stmt->execute([
    ':room_id'=>$room_id,
    ':month'=>$month,
    ':year'=>$year,
    ':start_date'=>$start_date,
    ':end_date'=>$end_date,
    ':total_days'=>$total_days
]);

$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

// -----------------------------
// 7️⃣ Cast numeric columns to proper types
// -----------------------------
foreach ($members as &$m) {
    $m['paid'] = (float)$m['paid'];
    $m['owed'] = (float)$m['owed'];
    $m['balance'] = (float)$m['balance'];
    $m['days_present'] = (int)$m['days_present'];
    $m['excluded'] = $m['excluded'] == 0 ? false : true;
    $m['member_id'] = (int)$m['member_id'];
}
unset($m);

// -----------------------------
// 8️⃣ Compute settlements
// -----------------------------
$debtors = [];
$creditors = [];
foreach($members as $m){
    if($m['balance'] < -0.01){
        $debtors[] = ['name'=>$m['name'], 'amount'=>(float)abs($m['balance']),'from_member'=>$m['member_id']];
    } elseif($m['balance'] > 0.01){
        $creditors[] = ['name'=>$m['name'], 'amount'=>(float)$m['balance'],'to_member'=>$m['member_id']];
    }
}

$settlements = [];
$i=0; $j=0;
while($i<count($debtors) && $j<count($creditors)){
    $amount = min($debtors[$i]['amount'],$creditors[$j]['amount']);
    $settlements[] = [
        'from'=>$debtors[$i]['name'],
        'from_id'=>$debtors[$i]['from_member'],
        'to'=>$creditors[$j]['name'],
        'to_id'=>$creditors[$j]['to_member'],
        'amount'=>(float)$amount
    ];
    $debtors[$i]['amount'] -= $amount;
    $creditors[$j]['amount'] -= $amount;
    if($debtors[$i]['amount'] <= 0.01) $i++;
    if($creditors[$j]['amount'] <= 0.01) $j++;
}

// -----------------------------
// 9️⃣ Output JSON
// -----------------------------
jsonResponse([
    'month' => date("F Y", strtotime($start_date)),
    'total_days' => $total_days,
    'total_expense' => (float)$total_amount,
    'members' => $members,
    'settlements' => $settlements,
    'frozen'=>false
]);
$db = null;
exit;
?>