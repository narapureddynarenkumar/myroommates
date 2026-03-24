<?php
require "../config/db.php";
require "../models/RoomCategory.php";
require "../utils/response.php";

$db = (new Database())->connect();
$model = new RoomCategory($db);

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['name']) || trim($data['name']) === '') {
    jsonResponse(['error' => 'Category name required'], 400);
    exit();
}

if ($model->create($data['room_id'],$data['name'], $data['created_by'])) {

    jsonResponse(['success'=>true,'newCategory'=>[
        'id' => (int)$model->id,
        'name' => $data['name']]
    ]);

} else {

    jsonResponse(['success'=>false,
        'error' => 'Unable to create category'
    ], 500);

}
?>