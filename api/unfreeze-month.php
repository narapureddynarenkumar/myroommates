<?php
require "../config/db.php";
require "../utils/response.php";
$conn = (new Database())->connect();
$data = json_decode(file_get_contents("php://input"), true);

$room_id = $data['room_id'];
$month   = $data['month'];
$year    = $data['year'];
// $user_id = 1; // from auth middleware

// --------------------------------------------------
// 1️⃣ CHECK ADMIN
// --------------------------------------------------
// $checkAdmin = $conn->prepare("
//   SELECT role FROM room_members
//   WHERE room_id=? AND user_id=?
// ");
// $checkAdmin->bind_param("ii", $room_id, $user_id);
// $checkAdmin->execute();
// $res = $checkAdmin->get_result()->fetch_assoc();

// if (!$res || $res['role'] != 'admin') {
//   echo json_encode(["error" => "Only admin can unfreeze"]);
//   exit;
// }

// --------------------------------------------------
// 2️⃣ CHECK SNAPSHOT
// --------------------------------------------------
$snap = $conn->prepare("
  SELECT id FROM calculation_snapshots
  WHERE room_id=? AND month=? AND year=? AND frozen=1
");
$snap->execute([$room_id, $month, $year]);
$snapRes = $snap->fetch(PDO::FETCH_ASSOC);

if (!$snapRes) {
  echo json_encode(["error" => "No frozen snapshot found","success"=>false]);
  exit;
}

$snapshot_id = $snapRes['id'];

// --------------------------------------------------
// 3️⃣ CHECK PAYMENTS (OPTIONAL SAFETY)
// --------------------------------------------------
$payCheck = $conn->prepare("
  SELECT COUNT(*) as paid_count
  FROM calculation_settlements
  WHERE snapshot_id=? AND status='paid'
");

$payCheck->execute([$snapshot_id]);
$paid = $payCheck->fetchColumn();

if ($paid > 0) {
  echo json_encode([
    "error" => "Cannot unfreeze, settlements already paid","success"=>false
  ]);
  exit;
}

// --------------------------------------------------
// 4️⃣ UNFREEZE
// --------------------------------------------------
$update = $conn->prepare("
  UPDATE calculation_snapshots
  SET frozen=0
  WHERE id=?
");
$update->execute([$snapshot_id]);


// --------------------------------------------------
// 5️⃣ OPTIONAL: DELETE SNAPSHOT DATA (CLEAN)
// --------------------------------------------------
$conn->prepare("DELETE FROM calculation_member_shares WHERE snapshot_id=?")->execute([$snapshot_id]);

$conn->prepare("DELETE FROM calculation_settlements WHERE snapshot_id=?")->execute([$snapshot_id]);

echo json_encode(["success" => true]);