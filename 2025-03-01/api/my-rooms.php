<?php
require "../config/db.php";
require "../models/Room.php";
require "../utils/response.php";

$db = (new Database())->connect();
$model = new Room($db);

$data = $model->get_rooms($_GET['userId']);

jsonResponse($data);
?>