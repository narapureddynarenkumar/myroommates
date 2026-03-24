<?php
require "../config/db.php";
require "../utils/response.php";

$conn = (new Database())->connect();

$snapshot_id = $_GET['snapshotId'];

$stmt = $conn->prepare("
  SELECT 
    s.id,
    m1.name AS from_member,
    m2.name AS to_member,
    s.amount,
    s.status
  FROM calculation_settlements s
  JOIN room_members m1 ON s.from_member = m1.id
  JOIN room_members m2 ON s.to_member = m2.id
  WHERE s.snapshot_id=?
");

$stmt->execute([$snapshot_id]);

$data = [];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $data[] = [
    "id" => $row['id'],
    "from_member" => $row['from_member'],
    "to_member" => $row['to_member'],
    "amount" => $row['amount'],
    "paid" => $row['status'] === 'paid'
  ];
}

jsonResponse($data);