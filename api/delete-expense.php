<?php

require "../config/db.php";
require "../models/Expense.php";
require "../utils/response.php";

$input = json_decode(file_get_contents("php://input"), true);

$db = (new Database())->connect();
$model = new Expense($db);

if ($model->delete($input["expense_id"], $input["room_id"])) {
  jsonResponse([
    "success" => true,
  ]);
} else {
  jsonResponse([
    "success" => false,
  ]);
}

?>
