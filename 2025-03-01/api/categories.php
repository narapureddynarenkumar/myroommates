<?php
require "../config/db.php";
require "../models/Category.php";
require "../utils/response.php";

$db = (new Database())->connect();
$model = new Category($db);

jsonResponse($model->getAll());
?>