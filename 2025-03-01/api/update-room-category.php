<?php
require "../config/db.php";
require "../models/RoomCategory.php";
require "../utils/response.php";

$db = (new Database())->connect();
$model = new RoomCategory($db);

$data = json_decode(file_get_contents("php://input"), true);

if (empty($data['name'])) {
    jsonResponse(["error" => "Romm name required"], 400);
}

if($model->update($data['id'],$data['name'],$data['updated_by'])){
    jsonResponse(['success'=>true,]);
}else{
    jsonResponse(['success'=>false,"error"   => $model->error_message ?? "Something went wrong"]);
}

?>