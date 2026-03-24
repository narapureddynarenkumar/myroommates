<?php

class Expense {

    private $conn;
    private $table = "expenses";

    public function __construct($db) {
        $this->conn = $db;
    }

    private function getOrCreateCategory($room_id, $category_name) {

    // 1. Check if exists
        $stmt = $this->conn->prepare("
            SELECT id
            FROM room_categories
            WHERE room_id = :room_id
              AND name = :name
              AND is_active = 1
        ");

        $stmt->execute([
            "room_id" => $room_id,
            "name" => ucfirst(strtolower(trim($category_name)))
        ]);

        $existing = $stmt->fetch();

        if ($existing) {
            return $existing["id"];
        }

        // 2. Create new
        $insert = $this->conn->prepare("
            INSERT INTO room_categories (room_id, name)
            VALUES (:room_id, :name)
        ");

        $insert->execute([
            "room_id" => $room_id,
            "name" => ucfirst(strtolower(trim($category_name)))
        ]);

        return $this->conn->lastInsertId();
    }


    // ------------------------------------
    // VALIDATE category belongs to room
    // ------------------------------------
    private function categoryBelongsToRoom($room_id, $category_id) {

        $sql = "
            SELECT id
            FROM room_categories
            WHERE id = :cid
              AND room_id = :rid
              AND is_active = 1
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            "cid" => $category_id,
            "rid" => $room_id
        ]);

        return $stmt->fetch();
    }

    // ------------------------------------
    // CREATE EXPENSE
    // ------------------------------------
    public function create($data) {

        // security check
        if (!$this->categoryBelongsToRoom($data["room_id"], $data["category_id"])) {
            throw new Exception("Invalid category for this room");
        }

        $sql = "
            INSERT INTO {$this->table}
            (room_id, paid_by, category_id, amount, note, expense_date,created_by)
            VALUES
            (:room_id, :member_id, :category_id, :amount, :note, :date,:created_by)
        ";

        $stmt = $this->conn->prepare($sql);

        $stmt->execute([
            "room_id"     => $data["room_id"],
            "member_id"   => $data["member_id"],
            "category_id" => $data["category_id"],
            "amount"      => $data["amount"],
            "note"        => $data["title"] ?? null,
            "date"        => date('Y-m-d', strtotime($data["date"])),
            "created_by"  =>$data["created_by"]
        ]);

        $id = $this->conn->lastInsertId();

        $sql = "SELECT
                    m.name AS member_name,
                    e.id,
                    e.room_id,
                    rc.name AS category_name,
                    e.expense_date,
                    e.note,
                    e.amount
                FROM
                    expenses e
                INNER JOIN room_members m ON
                    e.paid_by = m.id
                INNER JOIN room_categories rc ON
                    rc.id = e.category_id 
                WHERE e.id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function update($data)
    {
        $sql = "UPDATE expenses 
                SET amount = ?,
                    category_id = ?, 
                    paid_by = ?, 
                    note = ?, 
                    expense_date = ?
                WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        try{
            $this->conn->beginTransaction();
            $stmt->execute([
                            $data['amount'],
                            $data['category_id'],
                            $data['member_id'],
                            $data['note'],
                            date('Y-m-d', strtotime($data['date'])),
                            $data['id']
                        ]);
            $this->conn->commit();
            return true;
        }catch(PDOException $ex){
            $this->conn->rollBack();
            return false;
        }
    }

    // ------------------------------------
    // DELETE (SOFT)
    // ------------------------------------
    public function delete($id, $room_id)
    {
        try {
            // Start transaction
            $this->conn->beginTransaction();

            $sql = "
                UPDATE {$this->table}
                SET is_deleted = 1
                WHERE id = :id
                  AND room_id = :room_id
            ";

            $stmt = $this->conn->prepare($sql);

            $stmt->execute([
                "id" => $id,
                "room_id" => $room_id
            ]);

            // Optional: check if row was actually updated
            if ($stmt->rowCount() === 0) {
                throw new Exception("No expense found or already deleted.");
            }

            // Commit if successful
            $this->conn->commit();

            return true;

        } catch (Exception $e) {

            // Rollback if something failed
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }

            // Log error if needed
            // error_log($e->getMessage());

            return false;
        }
    }


    public function getAll($room_id, $month, $year, $member_id = null)
    {
        $sql = "SELECT
                    e.id,
                    e.room_id,
                    rc.name AS category,
                    e.expense_date,
                    e.note,
                    e.amount,
                    m.name AS member_name,
                    e.category_id,
                    e.paid_by AS member_id
                FROM expenses e
                INNER JOIN room_members m ON e.paid_by = m.id
                INNER JOIN room_categories rc ON rc.id = e.category_id
                WHERE e.room_id = ?
                AND e.is_deleted = 0
                AND MONTH(e.expense_date) = ?
                AND YEAR(e.expense_date) = ?";

        $params = [$room_id, $month, $year];

        // Add member filter dynamically
        if (!empty($member_id)) {
            $sql .= " AND e.paid_by = ?";
            $params[] = $member_id;
        }

        $sql .= " ORDER BY e.id DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function isMonthFrozen($room_id,$month,$year)
    {
        $sql = "SELECT COUNT(*) 
                FROM calculation_snapshots 
                WHERE room_id = ? 
                AND month = ? 
                AND year = ? 
                AND frozen = 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$room_id,$month,$year]);
        return $stmt->fetchColumn();
    }
}
