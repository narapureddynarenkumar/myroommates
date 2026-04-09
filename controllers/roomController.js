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
    return res.status(500).json({ message: 'Failed to load rooms' });
  }
};


/* =========================
   CREATE ROOM
=========================*/


exports.createRoom = async (req, res) => {
  const data = req.body;

  // -------------------- 1. Validation --------------------
  if (!data.name) {
    return res.status(400).json({ message: "Room name required" });
  }

  if (!data.created_by || !data.creator_phone) {
    return res.status(400).json({ message: "Creator info required" });
  }

  if (!Array.isArray(data.members)) {
    data.members = [];
  }

  const conn = await db.getConnection();

  try {
    await conn.beginTransaction();

    // -------------------- 2. Insert Room --------------------
    const [roomResult] = await conn.execute(
      "INSERT INTO rooms (name, created_by) VALUES (?, ?)",
      [data.name, data.created_by]
    );
    const room_id = roomResult.insertId;

    // -------------------- 3. Prepare Members --------------------
    const allPhones = [
      ...data.members.map(m => m.phone),
      data.creator_phone
    ];
    const uniquePhones = [...new Set(allPhones)];

    // -------------------- 4. Fetch existing users --------------------
    let users = [];
    if (uniquePhones.length > 0) {
      const placeholders = uniquePhones.map(() => '?').join(', ');
      const [rows] = await conn.execute(
        `SELECT id, phone, name FROM users WHERE phone IN (${placeholders})`,
        uniquePhones
      );
      users = rows;
    }

    const userMap = {};
    users.forEach(u => { userMap[u.phone] = u; }); // store full user object

    // -------------------- 5. Insert Members --------------------
    const insertMemberQuery = `
      INSERT INTO room_members 
        (room_id, name, phone, joined_at, user_id, role) 
      VALUES (?, ?, ?, ?, ?, ?)
    `;

    let creator_room_member_id = null;

    for (const phone of uniquePhones) {
      // Determine member name
      const existingUser = userMap[phone];
      const memberData = data.members.find(m => m.phone === phone);
      //const nameFromInput = memberData?.name || 'Unknown';
      //const name = existingUser ? existingUser.name : (phone === data.creator_phone ? existingUser.name : nameFromInput);

      const formattedDate = dayjs(memberData?.joinDate || new Date()).format('YYYY-MM-DD');
      const userId = existingUser ? existingUser.id : (phone === data.creator_phone ? data.created_by : null);
      const role = phone === data.creator_phone ? 'admin' : 'member';

      const [result] = await conn.execute(insertMemberQuery, [
        room_id,
        memberData?.name,
        phone,
        formattedDate,
        userId,
        role
      ]);
      if (phone === data.creator_phone) {
        creator_room_member_id = result.insertId;
      }
    }

    // -------------------- 6. Commit Transaction --------------------
    await conn.commit();

    return res.status(200).json({
      success: true,
      id: room_id,
      name: data.name,
      is_active: 1,
      room_member_id: creator_room_member_id
    });

  } catch (err) {
    await conn.rollback();
    console.error(err);
    return res.status(500).json({ success: false, message: 'Failed to create room' });
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
                rm.is_active,
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