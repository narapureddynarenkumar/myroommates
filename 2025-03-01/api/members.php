<?php
require "../config/database.php";
require "../models/Member.php";
require "../utils/response.php";

$db = (new Database())->connect();
$model = new Member($db);

$month = $_GET["month"] ?? date("m");
$year  = $_GET["year"] ?? date("Y");

$data = $model->getByMonth($month, $year);

jsonResponse($data);
?>