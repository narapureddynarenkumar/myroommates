<?php

declare(strict_types=1);

class Member
{
    private PDO $conn;
    private string $table = 'members';
    public $error_message;

    public function __construct(PDO $db)
    {
        $this->conn = $db;

        // Ensure PDO throws exceptions
        $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Get members of a room with status
     */
    public function getRoomMembers(int $roomId): array
    {
        $sql = "
            SELECT 
                rm.id,
                rm.name,
                rm.joined_at,
                rm.left_at,
                CASE 
                    WHEN rm.left_at IS NOT NULL THEN 'left'
                    ELSE 'active'
                END AS status,
                rm.id AS room_member_id
            FROM room_members rm 
            WHERE rm.room_id = :room_id
            ORDER BY rm.joined_at ASC
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute(['room_id' => $roomId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get members active during a specific month
     */
    public function getByMonth(int $month, int $year): array
    {
        $firstDay = sprintf('%04d-%02d-01', $year, $month);
        $lastDay  = date('Y-m-t', strtotime($firstDay));

        $sql = "
            SELECT 
                id,
                name,
                start_date,
                end_date,
                excluded,
                categories
            FROM {$this->table}
            WHERE start_date <= :lastDay
              AND (end_date IS NULL OR end_date >= :firstDay)
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            'firstDay' => $firstDay,
            'lastDay'  => $lastDay
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['categories'] = $row['categories']
                ? json_decode($row['categories'], true)
                : [];
        }

        return $rows;
    }

    public function validateMemberInRoom($phone, $room_id)
    {
        $sql = "SELECT COUNT(*) FROM room_members WHERE phone = ? AND room_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$phone, $room_id]);

        return $stmt->fetchColumn() > 0;
    }


    /**
     * Create member and assign to room
     */
    public function addMember(array $data)
    {
        try {

            // Basic validation
            if (
                empty($data['mobile_no']) ||
                empty($data['roomId']) ||
                empty($data['join_date']) ||
                empty($data['name'])
            ) {
                return false;
            }

            // Check if already exists
            if ($this->validateMemberInRoom($data['mobile_no'], $data['roomId'])) {
                return false;
            }

            $this->conn->beginTransaction();

            $stmt = $this->conn->prepare("
                INSERT INTO room_members (room_id, joined_at, name, phone)
                VALUES (:room_id, :join_date, :name, :phone)
            ");

            $stmt->execute([
                ':room_id'   => $data['roomId'],
                ':join_date' => date('Y-m-d', strtotime($data['join_date'])),
                ':name'      => trim($data['name']),
                ':phone'     => trim($data['mobile_no']),
            ]);

            $this->conn->commit();

            return true;

        } catch (Throwable $e) {

            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }

            throw $e;
        }
    }

    /**
     * create member rule
     */
     
     // public function createMemberRule($room_member_id,$year,$month,$excluded,$start,$end,$excludeCategories)
     // {
     //    try{
     //        $this->conn->beginTransaction();
     //        $stmt = $this->conn->prepare("
     //        INSERT INTO member_month_rules
     //        (room_member_id, year, month, excluded, start_date, end_date)
     //        VALUES (?, ?, ?, ?, ?, ?)
     //        ON DUPLICATE KEY UPDATE
     //        excluded = VALUES(excluded),
     //        start_date = VALUES(start_date),
     //        end_date = VALUES(end_date)
     //        ");

     //        $stmt->execute([
     //            $room_member_id,
     //            $year,
     //            $month,
     //            $excluded,
     //            $start,
     //            $end
     //        ]);
     //        $stmt = $this->conn->prepare("
     //            SELECT id FROM member_month_rules
     //            WHERE room_member_id = ? AND year = ? AND month = ?
     //        ");
     //        $stmt->execute([$room_member_id, $year, $month]);
     //        $ruleId = $stmt->fetchColumn();

     //        if (!$ruleId) {
     //            throw new Exception("Failed to retrieve rule ID");
     //        }

     //        // -------------------------------------------------
     //        // 3️⃣ Remove existing category exclusions
     //        // -------------------------------------------------
     //        $stmt = $this->conn->prepare("
     //            DELETE FROM member_month_category_exclusions
     //            WHERE rule_id = ?
     //        ");
     //        $stmt->execute([$ruleId]);

     //        // -------------------------------------------------
     //        // 4️⃣ Insert new category exclusions
     //        // -------------------------------------------------
     //        if (!empty($excludeCategories)) {

     //            $stmt = $this->conn->prepare("
     //                INSERT INTO member_month_category_exclusions
     //                (rule_id, category_id)
     //                VALUES (?, ?)
     //            ");

     //            foreach ($excludeCategories as $catId) {
     //                $stmt->execute([$ruleId, intval($catId)]);
     //            }
     //        }
     //        $this->conn->commit();
     //        return true;
     //    } catch (Throwable $e) {
     //        $this->conn->rollBack();
     //        throw $e; // Let controller handle logging
     //    }
     // }

    public function saveMonthlyRule($memberId, $year, $month, $excluded, $start, $end, $categories) {

        try {
            $this->conn->beginTransaction();

            // Insert or update rule
            $stmt = $this->conn->prepare("
                INSERT INTO member_month_rules
                (room_member_id, year, month, excluded, start_date, end_date)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    excluded = VALUES(excluded),
                    start_date = VALUES(start_date),
                    end_date = VALUES(end_date)
            ");

            $stmt->execute([$memberId, $year, $month, $excluded, $start, $end]);

            // 🔑 Get correct rule_id (important fix)
            $ruleStmt = $this->conn->prepare("
                SELECT id FROM member_month_rules
                WHERE room_member_id = ? AND year = ? AND month = ?
            ");
            $ruleStmt->execute([$memberId, $year, $month]);
            $ruleId = $ruleStmt->fetchColumn();

            // Remove old category exclusions
            $this->conn->prepare("
                DELETE FROM member_month_category_exclusions
                WHERE rule_id = ?
            ")->execute([$ruleId]);

            // Insert new categories ONLY if excluded = 1
            if (!empty($categories)) {
                $stmt = $this->conn->prepare("
                    INSERT INTO member_month_category_exclusions
                    (rule_id, category_id)
                    VALUES (?, ?)
                ");

                foreach ($categories as $catId) {
                    $stmt->execute([$ruleId, intval($catId)]);
                }
            }

            $this->conn->commit();
            return true;

        } catch (PDOException $ex) {
            $this->conn->rollBack();
            error_log($ex->getMessage());
            $this->error_message = "Save failed";
            return false;
        }
    }

    /**
     * Update member rule
     */
    public function updateRule(array $data): bool
    {
        $sql = "
            UPDATE members
            SET start_date = :start_date,
                end_date   = :end_date,
                excluded   = :excluded,
                categories = :categories
            WHERE id = :id
        ";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            'start_date' => $data['start_date'] ?? null,
            'end_date'   => $data['end_date'] ?? null,
            'excluded'   => $data['excluded'] ?? 0,
            'categories' => json_encode($data['categories'] ?? []),
            'id'         => $data['id']
        ]);
    }

    /**
     * Mark member as left from room
     */
    public function leaveRoom(int $roomMemberId): bool
    {
        $this->conn->beginTransaction();

        try {
            $stmt = $this->conn->prepare("
                UPDATE room_members
                SET left_at = NOW()
                    ,is_active = 0
                WHERE id = :id
            ");

            $stmt->execute(['id' => $roomMemberId]);

            $this->conn->commit();

            return true;

        } catch (Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }
}
