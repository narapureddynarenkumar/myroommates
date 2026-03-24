<?php
require "../config/db.php";
require "../utils/response.php";
$room_id = $_GET['room_id'];
$conn = (new Database())->connect();
$stmt = $conn->prepare("
  SELECT id, month, year, total_amount, frozen, created_at
  FROM calculation_snapshots
  WHERE room_id=? AND frozen=1
  ORDER BY year DESC, month DESC
");

$stmt->execute([$room_id]);

$data = [];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

  $monthName = date("F", mktime(0, 0, 0, $row['month'], 10));

  $data[] = [
    "id" => $row['id'],
    "month" => $row['month'],
    "year" => $row['year'],
    "month_name" => $monthName,
    "total_amount" => $row['total_amount'],
    "created_at" => $row['created_at']
  ];
}

jsonResponse($data);