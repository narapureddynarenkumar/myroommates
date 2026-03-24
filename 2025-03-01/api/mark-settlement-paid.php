<?php
require "../config/db.php";
require "../utils/response.php";
$conn = (new Database())->connect();
$data = json_decode(file_get_contents("php://input"), true);

$id = $data['id'];

$stmt = $conn->prepare("
  UPDATE calculation_settlements
  SET status='paid'
  WHERE id=?
");

$stmt->execute([$id]);
jsonResponse(["success" => true]);
