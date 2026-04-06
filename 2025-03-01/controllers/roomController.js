const db = require('../config/db');
const dayjs = require('dayjs');

/* =========================
   GET ROOMS
=========================*/
exports.getRooms = async (req, res) => {
  const { user_id } = req.params;

  try {
    const [rows] = await db.execute(
      `SELECT 
          r.id,
          r.name,
          rm_user.is_active,
          rm_user.id AS room_member_id,
          rm_user.joined_at,
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
      WHERE rm_user.user_id = ?`,
      [user_id]
    );

    return res.json(rows);

  } catch (err) {
    return res.status(500).json({ message: err.message });
  }
};


/* =========================
   CREATE ROOM
=========================*/
exports.createRoom = async (req, res) => {
  const data = req.body;

  if (!data.name) {
    return res.status(201).json({ message: "Room name required" });
  }

  const conn = await db.getConnection();

  try {
    await conn.beginTransaction();

    // Insert room
    const [roomResult] = await conn.execute(
      "INSERT INTO rooms (name, created_by) VALUES (?, ?)",
      [data.name, data.created_by]
    );

    const room_id = roomResult.insertId;

    // Prepare queries
    const userQuery = "SELECT id FROM users WHERE phone = ?";
    const insertMemberQuery = `
      INSERT INTO room_members 
      (room_id, name, phone, joined_at, user_id) 
      VALUES (?, ?, ?, ?, ?)
    `;

    // Insert categories
    const [roomCategory] = await conn.execute(
      `INSERT INTO room_categories 
       (room_id, master_category_id, name, created_by) 
       SELECT ?, id, name, ? FROM categories_master`,
      [room_id,  data.created_by]
    );

    for (const member of data.members) {
      const [userRows] = await conn.execute(userQuery, [member.phone]);

      const userId = userRows.length > 0 ? userRows[0].id : null;

      // const joinDate = member.joinDate
      const formatted = dayjs(member.joinDate).format('YYYY-MM-DD');

      await conn.execute(insertMemberQuery, [
        room_id,
        member.name,
        member.phone,
        formatted,
        userId
      ]);
    }

    await conn.commit();

    return res.status(200).json({
      success: true,
      id: room_id,
      name: data.name
    });

  } catch (err) {
    await conn.rollback();
    return res.status(500).json({success: false, message: err.message });
  } finally {
    conn.release();
  }
};


/* =========================
   UPDATE ROOM
=========================*/
exports.updateRoom = async (req, res) => {
  const data = req.body;

  const conn = await db.getConnection();

  try {
    await conn.beginTransaction();

    await conn.execute(
      `UPDATE rooms 
       SET name = ?, 
           updated_by = ?, 
           updated_at = NOW()
       WHERE id = ?`,
      [data.name.trim(), data.updated_by, data.roomId]
    );

    await conn.commit();

    return res.json({ success: true });

  } catch (err) {
    await conn.rollback();
    return res.status(500).json({ success: false, message: err.message });
  } finally {
    conn.release();
  }
};

exports.getRoomMembers = async (req, res) => {
  const {userId} = req.query ?? {}
    try {
      const conn = await db.getConnection();

        const { room_id } = req.params;

        const [roomMembers] = await conn.execute(
            `SELECT 
                r.id, 
                r.name,
                rm.id AS room_member_id,
                IFNULL(rm.user_id, 0) AS user_id,
                rm.name AS member_name,
                rm.joined_at,
                rm.left_at,
                CASE WHEN rm.is_active = 1 THEN 'Active' ELSE 'Left' END AS status
            FROM rooms r
            INNER JOIN room_members rm 
                ON r.id = rm.room_id
            WHERE r.id = ?`,
            [room_id]
        );

        // Filter for current user
        const filtered = roomMembers.filter(item => item.user_id == userId);

        res.json({
            data: roomMembers,
            name: roomMembers[0]?.name || null,
            roomMemberId: filtered[0]?.room_member_id || null
        });

    } catch (err) {
        console.error(err);
        res.status(500).json({ error: "Something went wrong" });
    }
};