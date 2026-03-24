<?php
require "../config/db.php";
require "../models/Member.php";
require "../utils/response.php";

$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    jsonResponse([
        'success' => false,
        'message' => 'Invalid JSON payload',
        'error_code' => 'INVALID_REQUEST'
    ], 400);
    exit;
}

$db = (new Database())->connect();
$model = new Member($db);

/* ---------------- FIELD VALIDATION ---------------- */

$errors = [];

if (empty($data['name'])) {
    $errors['name'] = 'Member name is required';
}

if (empty($data['mobile_no'])) {
    $errors['mobile_no'] = 'Mobile number is required';
} elseif (!preg_match('/^[0-9]{10}$/', $data['mobile_no'])) {
    $errors['mobile_no'] = 'Mobile number must be 10 digits';
}

if (empty($data['roomId'])) {
    $errors['roomId'] = 'Room ID is required';
}

if (empty($data['join_date'])) {
    $errors['join_date'] = 'Join date is required';
}

if (!empty($errors)) {
    jsonResponse([
        'success' => false,
        'message' => 'Validation failed',
        'error_code' => 'VALIDATION_ERROR',
        'errors' => $errors
    ], 422);
    exit;
}

/* ---------------- DUPLICATE CHECK ---------------- */

if ($model->validateMemberInRoom($data['mobile_no'], $data['roomId'])) {
    jsonResponse([
        'success' => false,
        'message' => 'This member is already part of the room',
        'error_code' => 'MEMBER_ALREADY_EXISTS'
    ], 409);
    exit;
}

/* ---------------- CREATE MEMBER ---------------- */

try {

    if ($model->addMember($data)) {
        jsonResponse([
            'success' => true,
            'message' => 'Member added successfully'
        ], 201);
    } else {
        jsonResponse([
            'success' => false,
            'message' => 'Unable to add member. Please try again.',
            'error_code' => 'CREATE_FAILED'
        ], 500);
    }

} catch (Throwable $e) {

    jsonResponse([
        'success' => false,
        'message' => 'Server error occurred',
        'error_code' => 'SERVER_ERROR'
    ], 500);

}