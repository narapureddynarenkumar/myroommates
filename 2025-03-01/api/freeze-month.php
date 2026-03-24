<?php
require "../config/db.php";
require "../utils/response.php";

$conn = (new Database())->connect();

$data = json_decode(file_get_contents("php://input"), true);

$room_id = $data['room_id'];
$month   = $data['month'];
$year    = $data['year'];
$total = $data['total'];
$shares = $data['shares'];
$settlements = $data['settlements'];
// Call live calculate logic first
// include 'calculate.php'; // or better move logic to function

// $result = json_decode(ob_get_clean(), true);

// ----------------------------------
// Create Snapshot
// ----------------------------------

$conn->beginTransaction();

$snapInsert = $conn->prepare("
    INSERT INTO calculation_snapshots
    (room_id, month, year, total_amount, frozen)
    VALUES (?, ?, ?, ?, 1)
    ON DUPLICATE KEY UPDATE
    total_amount=VALUES(total_amount),
    frozen=1
");
$snapInsert->execute([
    $room_id,
    $month,
    $year,
    $total
]);

$snapshotId = $conn->lastInsertId();

if (!$snapshotId) {
    $snapshotId = $conn->query("
        SELECT id FROM calculation_snapshots
        WHERE room_id=$room_id AND month=$month AND year=$year
    ")->fetchColumn();
}

// Delete old entries
$conn->prepare("DELETE FROM calculation_member_shares WHERE snapshot_id=?")
     ->execute([$snapshotId]);

$conn->prepare("DELETE FROM calculation_settlements WHERE snapshot_id=?")
     ->execute([$snapshotId]);

// Insert Shares
foreach ($shares as $s) {
    $conn->prepare("
        INSERT INTO calculation_member_shares
        (snapshot_id, member_id, share_amount)
        VALUES (?, ?, ?)
    ")->execute([
        $snapshotId,
        $s['member_id'],
        $s['owed']
    ]);
}

// Insert Settlements
foreach ($settlements as $set) {
    $conn->prepare("
        INSERT INTO calculation_settlements
        (snapshot_id, from_member, to_member, amount)
        VALUES (?, ?, ?, ?)
    ")->execute([
        $snapshotId,
        $set['from_id'],
        $set['to_id'],
        $set['amount']
    ]);
}

$conn->commit();

echo json_encode(["success" => true]);