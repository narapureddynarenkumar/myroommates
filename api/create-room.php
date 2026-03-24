<?php
require "../config/db.php";
require "../models/Room.php";
require "../utils/response.php";

$db = (new Database())->connect();
$model = new Room($db);
$data = json_decode(file_get_contents("php://input"), true);
$model->create_room($data);

jsonResponse(["success" => true]);
?>