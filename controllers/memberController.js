const db = require('../config/db');

/* =========================
   GET ROOM MEMBERS
=========================*/
exports.getRoomMembers = async (req, res) => {
  const { roomId } = req.params;

  try {
    const [rows] = await db.execute(
      `SELECT 
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
       WHERE rm.room_id = ?
       ORDER BY rm.joined_at ASC`,
      [roomId]
    );

    return res.json(rows);

  } catch (err) {
    return res.status(500).json({ message: err.message });
  }
};


/* =========================
   GET MEMBERS BY MONTH
=========================*/
exports.getMembersByMonth = async (req, res) => {
  const { month, year } = req.params;

  try {
    const firstDay = `${year}-${String(month).padStart(2, '0')}-01`;
    const lastDay = new Date(year, month, 0).toISOString().slice(0, 10);

    const [rows] = await db.execute(
      `SELECT 
          id,
          name,
          start_date,
          end_date,
          excluded,
          categories
       FROM members
       WHERE start_date <= ?
         AND (end_date IS NULL OR end_date >= ?)`,
      [lastDay, firstDay]
    );

    const formatted = rows.map(row => ({
      ...row,
      categories: row.categories ? JSON.parse(row.categories) : []
    }));

    return res.json(formatted);

  } catch (err) {
    return res.status(500).json({ message: err.message });
  }
};


/* =========================
   VALIDATE MEMBER IN ROOM
=========================*/
const validateMemberInRoom = async (phone, room_id, conn) => {
  const [rows] = await conn.execute(
    "SELECT COUNT(*) as count FROM room_members WHERE phone = ? AND room_id = ?",
    [phone, room_id]
  );

  return rows[0].count > 0;
};


/* =========================
   ADD MEMBER
=========================*/
exports.addMember = async (req, res) => {
  const data = req.body;

  if (!data.mobile_no || !data.roomId || !data.join_date || !data.name) {
    return res.status(400).json({ message: "Missing required fields" });
  }

  const conn = await db.getConnection();

  try {
    // Check duplicate
    const exists = await validateMemberInRoom(
      data.mobile_no,
      data.roomId,
      conn
    );

    if (exists) {
      return res.status(400).json({ message: "Member already exists" });
    }

    await conn.beginTransaction();

    await conn.execute(
      `INSERT INTO room_members (room_id, joined_at, name, phone)
       VALUES (?, ?, ?, ?)`,
      [
        data.roomId,
        new Date(data.join_date).toISOString().slice(0, 10),
        data.name.trim(),
        data.mobile_no.trim()
      ]
    );

    await conn.commit();

    return res.json({ success: true });

  } catch (err) {
    await conn.rollback();
    return res.status(500).json({ message: err.message });
  } finally {
    conn.release();
  }
};


/* =========================
   SAVE MONTHLY RULE
=========================*/
exports.saveMonthlyRule = async (req, res) => {
  const { memberId, year, month, excluded, start, end, categories } = req.body;

  const conn = await db.getConnection();

  try {
    await conn.beginTransaction();

    // Insert / Update rule
    await conn.execute(
      `INSERT INTO member_month_rules
       (room_member_id, year, month, excluded, start_date, end_date)
       VALUES (?, ?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE
         excluded = VALUES(excluded),
         start_date = VALUES(start_date),
         end_date = VALUES(end_date)`,
      [memberId, year, month, excluded, start, end]
    );

    // Get rule ID
    const [ruleRows] = await conn.execute(
      `SELECT id FROM member_month_rules
       WHERE room_member_id = ? AND year = ? AND month = ?`,
      [memberId, year, month]
    );

    const ruleId = ruleRows[0]?.id;

    // Delete old categories
    await conn.execute(
      `DELETE FROM member_month_category_exclusions WHERE rule_id = ?`,
      [ruleId]
    );

    // Insert new categories
    if (categories && categories.length > 0) {
      for (const catId of categories) {
        await conn.execute(
          `INSERT INTO member_month_category_exclusions
           (rule_id, category_id)
           VALUES (?, ?)`,
          [ruleId, parseInt(catId)]
        );
      }
    }

    await conn.commit();

    return res.json({ success: true });

  } catch (err) {
    await conn.rollback();
    console.error(err.message);
    return res.status(500).json({ success: false, message: "Save failed" });
  } finally {
    conn.release();
  }
};


/* =========================
   UPDATE RULE
=========================*/
exports.updateRule = async (req, res) => {
  const data = req.body;

  try {
    await db.execute(
      `UPDATE members
       SET start_date = ?,
           end_date = ?,
           excluded = ?,
           categories = ?
       WHERE id = ?`,
      [
        data.start_date || null,
        data.end_date || null,
        data.excluded || 0,
        JSON.stringify(data.categories || []),
        data.id
      ]
    );

    return res.json({ success: true });

  } catch (err) {
    return res.status(500).json({ message: err.message });
  }
};


/* =========================
   LEAVE ROOM
=========================*/
exports.leaveRoom = async (req, res) => {
  const { roomMemberId } = req.body;

  const conn = await db.getConnection();

  try {
    await conn.beginTransaction();

    await conn.execute(
      `UPDATE room_members
       SET left_at = NOW(),
           is_active = 0
       WHERE id = ?`,
      [roomMemberId]
    );

    await conn.commit();

    return res.json({ success: true });

  } catch (err) {
    await conn.rollback();
    return res.status(500).json({ message: err.message });
  } finally {
    conn.release();
  }
};