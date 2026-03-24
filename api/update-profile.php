<?php
require "../config/db.php";
require "../utils/response.php";

$conn = (new Database())->connect();

// Get JSON input
$data = json_decode(file_get_contents("php://input"), true);

// Validate input
$userId = $data['userId'] ?? null;
$name   = trim($data['name'] ?? '');
$email  = trim($data['email'] ?? '');

// Basic validation
if (!$userId || !$name || !$email) {
    echo json_encode([
        "success" => false,
        "message" => "All fields are required"
    ]);
    exit;
}

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid email format"
    ]);
    exit;
}

try {
    // Check if email already exists for another user
    $check = $conn->prepare("SELECT id FROM users WHERE email = :email AND id != :id");
    $check->execute([
        ':email' => $email,
        ':id' => $userId
    ]);

    if ($check->rowCount() > 0) {
        echo json_encode([
            "success" => false,
            "message" => "Email already in use"
        ]);
        exit;
    }

    // Update user
    $stmt = $conn->prepare("UPDATE users SET name = :name, email = :email WHERE id = :id");

    $success = $stmt->execute([
        ':name' => $name,
        ':email' => $email,
        ':id' => $userId
    ]);

    if ($success) {
        echo json_encode([
            "success" => true,
            "message" => "Profile updated successfully"
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "message" => "Update failed"
        ]);
    }

} catch (PDOException $e) {
    echo json_encode([
        "success" => false,
        "message" => "Server error",
        "error" => $e->getMessage() // remove in production
    ]);
}