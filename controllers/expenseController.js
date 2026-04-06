const db = require('../config/db');

// ------------------------------------
// GET OR CREATE CATEGORY
// ------------------------------------
exports.getOrCreateCategory = async (room_id, category_name) => {
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
exports.categoryBelongsToRoom = async (room_id, category_id) => {
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
exports.createExpense = async (req, res) => {
    try {
        const { room_id, member_id, category_id, amount, title, date, created_by } = req.body;

        const isValid = await exports.categoryBelongsToRoom(room_id, category_id);
        if (!isValid) {
            return res.status(201).json({ message: "Invalid category for this room", success: false });
        }

        const [year, month] = date.split('-');

         console.log(year, month)
         const isFrozen = await isMonthFrozen(room_id, month,year)
        if(isFrozen > 0){
            return res.status(201).json({ message: "Month is frozen. Cannot add expense" , success: false});
        }

        const formattedDate = new Date(date);

        const [result] = await db.execute(
            `INSERT INTO expenses
            (room_id, paid_by, category_id, amount, note, expense_date, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)`,
            [room_id, member_id, category_id, amount, title || null, formattedDate, created_by]
        );

        

        res.json({success: true, message: 'Expense added successfully'});
    } catch (err) {
        console.error(err);
        res.status(500).json({success: false, error: "Something went wrong" });
    }
}

// ------------------------------------
// UPDATE EXPENSE
// ------------------------------------
exports.updateExpense = async (req, res) => {
    const { id, room_id, member_id, category_id, amount, note, date } = req.body;

    const conn = await db.getConnection();
    // -----------------------------
        // 1️⃣ Get existing expense
        // -----------------------------
        const [rows] = await conn.execute(
            `SELECT room_id, DATE_FORMAT(expense_date, '%Y-%m-%d') AS expense_date FROM expenses WHERE id=?`,
            [id]
        );

        if (!rows.length) {
            return res.status(201).json({
                success: false,
                message: "Expense not found"
            });
        }

        const expense = rows[0];
        // const [year, month] = expense.expense_date.split('-')
        // -----------------------------
// 2️⃣ Check freeze logic (FIXED)
// -----------------------------

        let checkDate = expense.expense_date;

        // If user is changing date → use new date
        if (date && normalizeDate(date) !== expense.expense_date) {
            checkDate = date;
        }
        const [year, month] = checkDate.split('-')
         console.log(year, month)
        const isFrozen = await isMonthFrozen(expense.room_id, month, year);

        if (isFrozen > 0) {
            return res.status(400).json({
                success: false,
                message: date && normalizeDate(date) !== expense.expense_date
                    ? "Cannot move expense to a frozen month"
                    : "Cannot edit expense. Month is frozen"
            });
        }
        // -----------------------------
        // 2️⃣ Check freeze (OLD date)
        // -----------------------------
        // const frozenOld = await isMonthFrozen(expense.room_id, month,year);
        // if (frozenOld > 0) {
        //     return res.status(201).json({
        //         success: false,
        //         message: "Cannot edit expense. Month is frozen"
        //     });
        // }
        // if (date) {
        //     const [year,month] = date.split('-')
        //     const frozenNew = await isMonthFrozen(expense.room_id, month, year);

        //     if (frozenNew > 0) {
        //         return res.status(201).json({
        //             success: false,
        //             message: "Cannot move expense to a frozen month"
        //         });
        //     }
        // }

    try {
        await conn.beginTransaction();

        // const formattedDate = new Date(date);

        await conn.execute(
            `UPDATE expenses 
             SET amount = ?,
                 category_id = ?, 
                 paid_by = ?, 
                 note = ?, 
                 expense_date = ?
             WHERE id = ?
               AND room_id = ?`,
            [amount, category_id, member_id, note || null, date, id, room_id]
        );

        await conn.commit();
        res.json({ success: true , message: 'Expense updated successfully'});
    } catch (err) {
        await conn.rollback();
        console.error(err);
        res.status(500).json({ message: "Failed to update expense", success: false });
    } finally {
        conn.release();
    }
}

// ------------------------------------
// DELETE (SOFT)
// ------------------------------------
exports.deleteExpense = async (req, res) => {
    const { id, room_id } = req.body;
    const conn = await db.getConnection();

    const [rows] = await conn.execute(
            `SELECT room_id,DATE_FORMAT(expense_date, '%Y-%m-%d') AS  expense_date FROM expenses WHERE id=?`,
            [id]
        );

        if (!rows.length) {
            return res.status(404).json({
                success: false,
                message: "Expense not found"
            });
        }

        const expense = rows[0];
        const [year, month] = expense.expense_date

        // -----------------------------
        // 2️⃣ Check freeze
        // -----------------------------
        const frozen = await isMonthFrozen( expense.room_id, month,year );

        if (frozen > 0) {
            return res.status(201).json({
                success: false,
                message: "Cannot delete expense. Month is frozen"
            });
        }

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
        res.json({ success: true });
    } catch (err) {
        await conn.rollback();
        console.error(err);
        res.status(500).json({ error: "Failed to delete expense" });
    } finally {
        conn.release();
    }
}

// ------------------------------------
// GET ALL EXPENSES
// ------------------------------------
exports.getAllExpenses = async (req, res) => {
    try {
        const { room_id, month, year } = req.params;
        const { memberId } = req.query ?? {};

        // Validation
        if (!room_id || !month || !year) {
            return res.status(201).json({ error: "Missing required parameters" });
        }

        if (isNaN(month) || isNaN(year)) {
            return res.status(201).json({ error: "Invalid month or year" });
        }

        // Format dates properly
        const formattedMonth = String(month).padStart(2, "0");
        const lastDay = new Date(year, month, 0).getDate();

        const startDate = `${year}-${formattedMonth}-01`;
        const endDate = `${year}-${formattedMonth}-${lastDay}`;

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
              AND e.expense_date BETWEEN ? AND ?
        `;

        const params = [room_id, startDate, endDate];

        if (memberId) {
            sql += " AND e.paid_by = ?";
            params.push(memberId);
        }

        sql += " ORDER BY e.id DESC";

        const [rows] = await db.execute(sql, params);

        const frozen = await isMonthFrozen(room_id, month, year);

        res.json({
            expenses: rows,
            frozen: frozen > 0 ? true : false
        });

    } catch (err) {
        console.error(err);
        res.status(500).json({ error: "Failed to fetch expenses" });
    }
};

// ------------------------------------
// CHECK MONTH FROZEN
// ------------------------------------
const isMonthFrozen = async (room_id, month, year) => {
   
        const [rows] = await db.execute(
            `SELECT COUNT(*) AS count
             FROM calculation_snapshots
             WHERE room_id = ?
               AND month = ?
               AND year = ?
               AND frozen = 1`,
            [room_id, month, year]
        );

        return rows[0].count 
    
}

function normalizeDate(date) {
    const d = new Date(date);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
}