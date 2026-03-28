const db = require('../config/db');

// ---------------------------
// NORMALIZE
// ---------------------------
function normalize(name) {
  return name
    ?.trim()
    .toLowerCase()
    .replace(/\b\w/g, c => c.toUpperCase());
}

// ---------------------------
// EXISTS
// ---------------------------
async function exists(conn, room_id, name, exclude_id = null) {
  let sql = `
    SELECT 1
    FROM room_categories
    WHERE room_id = ?
      AND LOWER(name) = LOWER(?)
      AND is_active = 1
  `;

  const params = [room_id, name];

  if (exclude_id) {
    sql += ` AND id != ?`;
    params.push(exclude_id);
  }

  sql += ` LIMIT 1`;

  const [rows] = await conn.execute(sql, params);

  return rows.length > 0;
}

// ---------------------------
// GET BY ID
// ---------------------------
async function getById(conn, id) {
  const [rows] = await conn.execute(
    `SELECT * FROM room_categories WHERE id = ? LIMIT 1`,
    [id]
  );

  return rows[0] || null;
}

// ---------------------------
// GET BY ROOM
// ---------------------------
exports.getByRoom = async (req, res) => {
  try {
    const { room_id } = req.params;

    const [rows] = await db.execute(
      `SELECT id, name
       FROM room_categories
       WHERE room_id = ?
         AND is_active = 1
       ORDER BY name`,
      [room_id]
    );

    return res.json(rows);

  } catch (err) {
    console.error(err);
    return res.status(500).json({ message: "Server error" });
  }
};

// ---------------------------
// CREATE
// ---------------------------
exports.create = async (req, res) => {
  const { room_id, name, created_by } = req.body;

  if (!room_id || !name) {
    return res.status(400).json({ message: "room_id and name required" });
  }

  const normalizedName = normalize(name);
  const conn = await db.getConnection();

  try {
    await conn.beginTransaction();

    const isExists = await exists(conn, room_id, normalizedName);

    if (isExists) {
      await conn.rollback();
      return res.status(400).json({
        message: "Category already exists in this room"
      });
    }

    const [result] = await conn.execute(
      `INSERT INTO room_categories (room_id, name, created_by)
       VALUES (?, ?, ?)`,
      [room_id, normalizedName, created_by]
    );

    await conn.commit();

    return res.json({
      success: true,
      id: result.insertId
    });

  } catch (err) {
    await conn.rollback();
    console.error(err);
    return res.status(500).json({ message: "Server error" });
  } finally {
    conn.release();
  }
};

// ---------------------------
// UPDATE
// ---------------------------
exports.update = async (req, res) => {
  const { id } = req.params;
  const { name } = req.body;

  if (!name) {
    return res.status(400).json({ message: "Name required" });
  }

  const normalizedName = normalize(name);
  const conn = await db.getConnection();

  try {
    await conn.beginTransaction();

    const current = await getById(conn, id);

    if (!current) {
      await conn.rollback();
      return res.status(404).json({ message: "Category not found" });
    }

    const isExists = await exists(
      conn,
      current.room_id,
      normalizedName,
      id
    );

    if (isExists) {
      await conn.rollback();
      return res.status(400).json({
        message: "Category already exists in this room"
      });
    }

    await conn.execute(
      `UPDATE room_categories
       SET name = ?
       WHERE id = ?`,
      [normalizedName, id]
    );

    await conn.commit();

    return res.json({ success: true });

  } catch (err) {
    await conn.rollback();
    console.error(err);
    return res.status(500).json({ message: err.message });
  } finally {
    conn.release();
  }
};

// ---------------------------
// DELETE (SOFT)
// ---------------------------
exports.delete = async (req, res) => {
  const { id } = req.params;
  const conn = await db.getConnection();

  try {
    await conn.beginTransaction();

    await conn.execute(
      `UPDATE room_categories
       SET is_active = 0
       WHERE id = ?`,
      [id]
    );

    await conn.commit();

    return res.json({ success: true });

  } catch (err) {
    await conn.rollback();
    console.error(err);
    return res.status(500).json({ message: "Delete failed" });
  } finally {
    conn.release();
  }
};