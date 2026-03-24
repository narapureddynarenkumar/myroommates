<?php

class RoomCategory {

    private $conn;
    private $table = "room_categories";

    public $id;
    public $error_message;

    public function __construct($db) {
        $this->conn = $db;
    }

    // ---------------------------
    // GET BY ROOM
    // ---------------------------
    public function getByRoom($room_id) {

        $sql = "
            SELECT id, name
            FROM {$this->table}
            WHERE room_id = :room_id
              AND is_active = 1
            ORDER BY name
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute(["room_id" => $room_id]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ---------------------------
    // CREATE
    // ---------------------------
    public function create($room_id, $name, $created_by) {

        $name = $this->normalize($name);

        if ($this->exists($room_id, $name)) {
            $this->error_message = "Category already exists in this room";
            return false;
        }

        try {
            $this->conn->beginTransaction();

            $sql = "
                INSERT INTO {$this->table} (room_id, name, created_by)
                VALUES (:room_id, :name, :created_by)
            ";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                "room_id"    => $room_id,
                "name"       => $name,
                "created_by" => $created_by
            ]);

            $this->id = $this->conn->lastInsertId();

            $this->conn->commit();
            return true;

        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log($e->getMessage());
            $this->error_message = "Server error";
            return false;
        }
    }

    // ---------------------------
    // UPDATE
    // ---------------------------
    public function update($id, $name, $user_id) {

        $name = $this->normalize($name);

        try {
            $this->conn->beginTransaction();

            $current = $this->getById($id);

            if (!$current) {
                $this->error_message = "Category not found";
            }

            if ($this->exists($current['room_id'], $name, $id)) {
                $this->error_message = "Category already exists in this room";
            }

            $sql = "
                UPDATE {$this->table}
                SET name = :name
                WHERE id = :id
            ";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                "name" => $name,
                "id"   => $id
            ]);

            $this->conn->commit();
            return true;

        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log($e->getMessage());
            $this->error_message = $e->getMessage();
            return false;
        }
    }

    // ---------------------------
    // SOFT DELETE
    // ---------------------------
    public function delete($id) {

        try {
            $this->conn->beginTransaction();

            $sql = "
                UPDATE {$this->table}
                SET is_active = 0
                WHERE id = :id
            ";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute(["id" => $id]);

            $this->conn->commit();
            return true;

        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log($e->getMessage());
            $this->error_message = "Delete failed";
            return false;
        }
    }

    // ---------------------------
    // EXISTS (Optimized)
    // ---------------------------
    private function exists($room_id, $name, $exclude_id = null) {

        $sql = "
            SELECT 1
            FROM {$this->table}
            WHERE room_id = :room_id
              AND LOWER(name) = LOWER(:name)
              AND is_active = 1
        ";

        if ($exclude_id) {
            $sql .= " AND id != :exclude_id";
        }

        $sql .= " LIMIT 1";

        $stmt = $this->conn->prepare($sql);

        $params = [
            "room_id" => $room_id,
            "name"    => $name
        ];

        if ($exclude_id) {
            $params["exclude_id"] = $exclude_id;
        }

        $stmt->execute($params);

        return (bool) $stmt->fetch();
    }

    // ---------------------------
    // GET BY ID
    // ---------------------------
    private function getById($id) {

        $sql = "SELECT * FROM {$this->table} WHERE id = :id LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute(["id" => $id]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // ---------------------------
    // NORMALIZE
    // ---------------------------
    private function normalize($name) {
        $name = trim($name);
        return mb_convert_case($name, MB_CASE_TITLE, "UTF-8");
    }
}