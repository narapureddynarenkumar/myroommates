<?php

require "../config/db.php";
require "../utils/response.php";


$db = (new Database())->connect();

$room_id = $_GET['roomId'];
$user_id = $_GET['userId'];

$sql = "SELECT 
			r.id, 
			r.name,
			rm.id AS room_member_id,
			IFNULL(rm.user_id,0) AS user_id,
			rm.name AS member_name,
			rm.joined_at,
			rm.left_at,
			CASE WHEN rm.is_active = 1 THEN 'Active' ELSE 'Left' END status
		FROM rooms r INNER JOIN room_members rm 
		ON r.id = rm.room_id
		WHERE r.id = ?";
$stmt = $db->prepare($sql);
$stmt->execute([$room_id]);
$roomMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filtered = array_filter($roomMembers, function ($item) use ($user_id) {
    return $item['user_id'] == $user_id;
});


jsonResponse([
    "data" => $roomMembers,
    "name" => $roomMembers[0]["name"] ?? NULL,
    "roomMemberId" => $filtered[0]["room_member_id"] ?? NULL
]);
?>