<?php

class Category {

    private $conn;
    private $table = "categories";

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getAll() {
        $sql = "SELECT id, name FROM {$this->table} WHERE is_active = 1 ORDER BY name";
        return $this->conn->query($sql)->fetchAll();
    }

    public function create()
    {
        $name = ucfirst(strtolower(trim($name)));

        $stmt = $conn->prepare("SELECT id FROM categories WHERE name = ?");
        $stmt->execute([$name]);

        $id = $stmt->fetchColumn();

        if (!$id) {
            $stmt = $conn->prepare("INSERT INTO categories(name) VALUES(?)");
            $stmt->execute([$name]);
            $id = $conn->lastInsertId();
        }

        return $id;

    }
}
