<?php
require "../config/db.php";
require "../models/Member.php";
require "../utils/response.php";

$roomId = isset($_GET['roomId']) ? (int)$_GET['roomId'] : 0;

if ($roomId <= 0) {
    jsonResponse(['message' => 'Invalid roomId'], 400);
    exit;
}

$db = (new Database())->connect();
$model = new Member($db);

$data = $model->getRoomMembers($roomId);

jsonResponse($data);
?>
