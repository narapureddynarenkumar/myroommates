<?php
require_once '../config/db.php';
require_once '../utils/uuid.php';

class Expense {
  public static function allByRoom($roomId) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM expenses WHERE room_id = ?");
    $stmt->execute([$roomId]);
    return $stmt->fetchAll();
  }

  public static function create($data) {
    global $pdo;
    $stmt = $pdo->prepare("
	  INSERT INTO rules
	  (room_id, month, type, member_id, mode, categories, from_date, to_date)
	  VALUES (?, ?, ?, ?, ?, ?, ?, ?)
	");

	foreach ($data['rules'] as $r) {
	  $stmt->execute([
		$data['roomId'],
		$data['month'],
		$r['type'],
		$r['memberId'],
		$r['mode'] ?? null,
		isset($r['categories']) ? json_encode($r['categories']) : null,
		$r['from'] ?? null,
		$r['to'] ?? null,
	  ]);
	}
  }
}
