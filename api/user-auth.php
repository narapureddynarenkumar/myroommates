<?php
require "../config/db.php";
require "../models/Auth.php";
require "../utils/response.php";
require "../core/Logger.php";

$logger = new Logger(__DIR__ . '/../logs/app.log');

$data = json_decode(file_get_contents("php://input"), true);

$db = (new Database())->connect();
$auth = new Auth($db);


if ($_GET['action'] == "signup") {
    echo json_encode(
        $auth->signup(trim($data['name']), trim($data['phone']), trim($data['password']))
    );
}

if ($_GET['action'] == "login") {
    echo json_encode(
        $auth->login(trim($data['phone']), trim($data['password']))
    );
}

if ($_GET['action'] == "forgot") {
    echo json_encode(
        $auth->forgotPassword(trim($data['phone']))
    );
}
?>