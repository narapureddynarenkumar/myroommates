<?php

require "../config/db.php";
require "../models/Expense.php";
require "../utils/response.php";

$input = json_decode(file_get_contents("php://input"), true);

if (!$input) {
    jsonResponse(["error" => "Invalid payload"], 400);
}

$required = ["room_id", "member_id", "category_id", "amount", "date"];

foreach ($required as $field) {
    if (!isset($input[$field])) {
        jsonResponse(["error" => "$field required"], 400);
    }
}

try {
    $db = (new Database())->connect();
    $model = new Expense($db);

    $rec = $model->create($input);

    jsonResponse([
        "success" => true,
        "data" => $rec,
    ]);
} catch (Exception $e) {
    jsonResponse(
        [
            "success" => false,
            "message" => $e->getMessage(),
        ],
        400
    );
}
?>
