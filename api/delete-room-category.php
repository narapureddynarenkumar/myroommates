<?php
require "../config/db.php";
require "../models/RoomCategory.php";
require "../utils/response.php";

$db = (new Database())->connect();
$model = new RoomCategory($db);

$data = json_decode(file_get_contents("php://input"), true);


if($model->delete($data['id'])){
    jsonResponse([
        "success" => true,
       
    ], 201);
}
else {

    jsonResponse([
        "success" => false,
        "error"   => $model->error_message ?? "Something went wrong"
    ],);
}
?>