<?php
require "../config/db.php";
require "../models/Member.php";
require "../utils/response.php";

$data = json_decode(file_get_contents("php://input"), true);

$room_member_id = intval($data['member_id']);
$excluded       = intval($data['excluded'] ?? 0);
$year           = intval($data['year']);
$month          = intval($data['month']);

$start = $data['startDate'] ?? null;
$end   = $data['endDate'] ?? null;
$excludeCategories = $data['categories'] ?? [];


// 🚀 BUSINESS RULE
if ($excluded == 1) {

    // fully excluded member
    $start = null;
    $end   = null;

    // remove category exclusions
    $excludeCategories = [];
}

$db = (new Database())->connect();
$model = new Member($db);

    if ($model->saveMonthlyRule(
        $room_member_id,
        $year,
        $month,
        $excluded,
        $start,
        $end,
        $excludeCategories
        )) {
        jsonResponse(["success" => true]);
    } else {
        jsonResponse(["success" => false]);
    } 


?>