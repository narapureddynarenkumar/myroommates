<?php

require "../config/db.php";
require "../models/Expense.php";
require "../utils/response.php";
$db = (new Database())->connect();
$model = new Expense($db);


$room_id = $_GET["room_id"] ?? null;
$month = $_GET["month"];
$year = $_GET["year"];
$member_id = $_GET['member_id'] ?? null;

if (!$room_id) {
    jsonResponse(["error" => "room_id required"], 400);
}

$data = $model->getAll($room_id,$month, $year,$member_id);

jsonResponse(['expenses'=>$data,'frozen'=>$model->isMonthFrozen($room_id,$month, $year) == 1 ? true : false]);

?>