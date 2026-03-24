<?php


class Room{
    private $conn;

    public function __construct($db){
        $this->conn = $db;
    }

    public function get_rooms($user_id) {
        $sql = "SELECT 
                    r.id,
                    r.name,
                    rm_user.is_active,
                    rm_user.id AS room_member_id,

                    (SELECT COUNT(*) 
                     FROM room_members rm 
                     WHERE rm.room_id = r.id) AS members,

                    (SELECT COALESCE(SUM(e.amount), 0)
                     FROM expenses e 
                     WHERE e.room_id = r.id 
                       AND e.is_deleted = 0) AS amount,

                    (SELECT MAX(IFNULL(e.updated_at, e.created_at))
                     FROM expenses e 
                     WHERE e.room_id = r.id 
                       AND e.is_deleted = 0) AS updated_at

                FROM rooms r

                INNER JOIN room_members rm_user 
                    ON r.id = rm_user.room_id

                WHERE rm_user.user_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    }

    public function create_room($data)
    {
        if (empty($data['name'])) {
            jsonResponse(['message' => 'Room name required'],400);
            return;
        }

        try {
            $this->conn->beginTransaction();

            // Insert room
            $stmt = $this->conn->prepare("INSERT INTO rooms (name, created_by) VALUES (?,?)");
            $stmt->execute([$data['name'],$data["created_by"]]);

            $room_id = $this->conn->lastInsertId();

            $userStmt = $this->conn->prepare(
                "SELECT id FROM users WHERE phone = ?"
            );
            // Prepare once
            $rommmemberStmt = $this->conn->prepare("
                INSERT INTO room_members (room_id, name,phone, joined_at, user_id) 
                VALUES (?, ?, ?,?,?)
            ");

            foreach ($data['members'] as $member) {

                $userStmt->execute([$member['phone']]);
                $existing = $userStmt->fetch(PDO::FETCH_ASSOC);

                $userId = $existing ? $existing['id'] : null;

                $joinDate = date('Y-m-d', strtotime($member['joinDate']));

                $rommmemberStmt->execute([
                    $room_id,
                    $member['name'],
                    $member['phone'],
                    $joinDate,
                    $userId
                ]);
            }

            $this->conn->commit();

            jsonResponse( [
                'id'   => $room_id,
                'name' => $data['name']
            ],201);

        } catch (Exception $e) {
            $this->conn->rollBack();
            jsonResponse(['message' => $e->getMessage()],500);
        }
    }

    public function update($data)
    {
        try{
            $this->conn->beginTransaction();
            $sql = "UPDATE rooms 
                    SET name = ?
                        ,updated_by = ?
                        , updated_at = NOW() 
                    WHERE id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$data['name'],$data['updated_by'],$data['roomId']]);
            $this->conn->commit();
            return true;
        } catch (Exception $e){
            $this->conn->rollBack();
            return false;
        }  
    }
}
?>