<?php

require "../config/db.php";
require "../models/RoomCategory.php";
require "../utils/response.php";

// Read input
$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

// Validate JSON
if (!is_array($data)) {
    jsonResponse(["error" => "Invalid JSON input"], 400);
    exit;
}

// Database
$db = (new Database())->connect();
$model = new RoomCategory($db);

// ---------------------------
// VALIDATION FUNCTION
// ---------------------------
function validate($data) {
    if (empty($data['room_id'])) return "room_id is required";
    if (!is_numeric($data['room_id'])) return "Invalid room_id";

    if (empty($data['name'])) return "name is required";

    if (empty($data['created_by'])) return "created_by is required";
    if (!is_numeric($data['created_by'])) return "Invalid created_by";

    return null;
}

// Run validation
$error = validate($data);

if ($error) {
    jsonResponse([
        "success" => false,
        "error"   => $error
    ], 400);
    exit;
}

// Create
if ($model->create($data['room_id'], $data['name'], $data['created_by'])) {

    jsonResponse([
        "success" => true,
        "id"      => $model->id
    ], 201);

} else {

    jsonResponse([
        "success" => false,
        "error"   => $model->error_message ?? "Something went wrong"
    ],);
}