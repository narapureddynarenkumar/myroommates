const db = require('../config/db');

// ------------------------------------
// GET OR CREATE CATEGORY
// ------------------------------------
exports.getOrCreateCategory = async(req, res) {
    const {room_id, category_name} = req.body
    const name = category_name.trim().toLowerCase().replace(/^./, c => c.toUpperCase());

    // 1. Check if exists
    const [rows] = await db.execute(
        `SELECT id
         FROM room_categories
         WHERE room_id = ?
           AND name = ?
           AND is_active = 1`,
        [room_id, name]
    );

    if (rows.length > 0) {
        return rows[0].id;
    }

    // 2. Insert new
    const [result] = await db.execute(
        `INSERT INTO room_categories (room_id, name)
         VALUES (?, ?)`,
        [room_id, name]
    );

    return result.insertId;
}

// ------------------------------------
// VALIDATE CATEGORY BELONGS TO ROOM
// ------------------------------------
 exports.categoryBelongsToRoom = async(room_id, category_id) {
    const [rows] = await db.execute(
        `SELECT id
         FROM room_categories
         WHERE id = ?
           AND room_id = ?
           AND is_active = 1`,
        [category_id, room_id]
    );

    return rows.length > 0;
}

// ------------------------------------
// CREATE EXPENSE
// ------------------------------------
exports.createExpense = async(req, res) {
    const {droom_id,member_id,category_id,amount,title,date ,created_by} = req.body

    const isValid = await categoryBelongsToRoom(data.room_id, data.category_id);

    if (!isValid) {
        throw new Error("Invalid category for this room");
    }

    const formattedDate = new Date(date).toISOString().slice(0, 10);

    const [result] = await db.execute(
        `INSERT INTO expenses
        (room_id, paid_by, category_id, amount, note, expense_date, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)`,
        [
            room_id,
            member_id,
            category_id,
            amount,
            title || null,
            formattedDate,
            created_by
        ]
    );

    const id = result.insertId;

    const [rows] = await db.execute(
        `SELECT
            m.name AS member_name,
            e.id,
            e.room_id,
            rc.name AS category_name,
            e.expense_date,
            e.note,
            e.amount
        FROM expenses e
        INNER JOIN room_members m ON e.paid_by = m.id
        INNER JOIN room_categories rc ON rc.id = e.category_id
        WHERE e.id = ?`,
        [id]
    );

    return rows[0];
}

// ------------------------------------
// UPDATE EXPENSE
// ------------------------------------
async function updateExpense(data) {
    const conn = await db.getConnection();

    try {
        await conn.beginTransaction();

        const formattedDate = new Date(data.date).toISOString().slice(0, 10);

        await conn.execute(
            `UPDATE expenses 
             SET amount = ?,
                 category_id = ?, 
                 paid_by = ?, 
                 note = ?, 
                 expense_date = ?
             WHERE id = ?`,
            [
                data.amount,
                data.category_id,
                data.member_id,
                data.note,
                formattedDate,
                data.id
            ]
        );

        await conn.commit();
        return true;

    } catch (err) {
        await conn.rollback();
        return false;
    } finally {
        conn.release();
    }
}

// ------------------------------------
// DELETE (SOFT)
// ------------------------------------
exports.function deleteExpense = async(req, res) {
    const {id, room_id} = req.body;
    const conn = await db.getConnection();

    try {
        await conn.beginTransaction();

        const [result] = await conn.execute(
            `UPDATE expenses
             SET is_deleted = 1
             WHERE id = ?
               AND room_id = ?`,
            [id, room_id]
        );

        if (result.affectedRows === 0) {
            throw new Error("No expense found or already deleted.");
        }

        await conn.commit();
        return true;

    } catch (err) {
        await conn.rollback();
        return false;
    } finally {
        conn.release();
    }
}

// ------------------------------------
// GET ALL EXPENSES
// ------------------------------------
exports.function getAllExpenses = async(req, res) {
    const {room_id, month, year, member_id } = req.query.params

    let sql = `
        SELECT
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
        AND YEAR(e.expense_date) = ?
    `;

    const params = [room_id, month, year];

    if (member_id) {
        sql += " AND e.paid_by = ?";
        params.push(member_id);
    }

    sql += " ORDER BY e.id DESC";

    const [rows] = await db.execute(sql, params);
    return rows;
}

// ------------------------------------
// CHECK MONTH FROZEN
// ------------------------------------
exports.isMonthFrozen = async() {
    const {room_id, month, year} = req.params
    const [rows] = await db.execute(
        `SELECT COUNT(*) AS count
         FROM calculation_snapshots
         WHERE room_id = ?
           AND month = ?
           AND year = ?
           AND frozen = 1`,
        [room_id, month, year]
    );

    return rows[0].count;
}