<?php

require "../config/db.php";
require "../models/Member.php";
require "../utils/response.php";
require "../core/Logger.php";

$logger = new Logger(__DIR__ . '/../logs/app.log');

$input = json_decode(file_get_contents("php://input"), true);

$db = (new Database())->connect();
$model = new Member($db);

try{
   $model->leaveRoom($input['roomMemberId']);
   jsonResponse([
        'success' => true,
    ]);
   $logger->warning("Member already exists in this room.");
}catch (Throwable $e) {

    $logger->exception($e);

    jsonResponse([
        'success' => false,
        'message' => 'Internal server error'
    ], 500);
}
     
?>