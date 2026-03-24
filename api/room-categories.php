<?php
require "../config/db.php";
require "../models/RoomCategory.php";
require "../utils/response.php";

$db = (new Database())->connect();
$model = new RoomCategory($db);

$room_id = $_GET["room_id"] ?? null;

if (!$room_id) {
    jsonResponse(["error" => "room_id required"], 400);
}

jsonResponse($model->getByRoom($room_id));
?>