<?php

require "../config/db.php";
require "../models/Expense.php";
require "../utils/response.php";

$input = json_decode(file_get_contents("php://input"), true);

$db = (new Database())->connect();
    $model = new Expense($db);

    if($model->update($input)){
         jsonResponse([
        "success" => true,
         "message"=>"Updated successfully!"
    ]);
    }else{
      jsonResponse([
        "success" => false,
      
    ]);
    }
     

?>