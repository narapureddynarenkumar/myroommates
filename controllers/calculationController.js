const db = require('../config/db');

exports.getMonthlyCalculation = async (req, res) => {
    try {

        // -----------------------------
        // 1️⃣ Inputs
        // -----------------------------
        const room_id = parseInt(req.query.room_id) || 0;
        const month = parseInt(req.query.month) || (new Date().getMonth() + 1);
        const year = parseInt(req.query.year) || new Date().getFullYear();

        if (!room_id) {
            return res.status(400).json({ error: "room_id required" });
        }

        // -----------------------------
        // 2️⃣ Date helpers
        // -----------------------------
        const pad = (n) => n.toString().padStart(2, '0');

        const total_days = new Date(year, month, 0).getDate();

        const start_date = `${year}-${pad(month)}-01`;
        const end_date = `${year}-${pad(month)}-${pad(total_days)}`;

        // -----------------------------
        // 3️⃣ Snapshot check
        // -----------------------------
        const [snapRows] = await db.execute(
            `SELECT id, total_amount FROM calculation_snapshots
             WHERE room_id=? AND month=? AND year=? AND frozen=1`,
            [room_id, month, year]
        );

        if (snapRows.length > 0) {
            const snapshot = snapRows[0];

            const [shares] = await db.execute(
                `SELECT m.name, share_amount AS owed
                 FROM calculation_member_shares ms
                 JOIN room_members m ON ms.member_id = m.id
                 WHERE snapshot_id=?`,
                [snapshot.id]
            );

            const [settlements] = await db.execute(
                `SELECT m1.name AS from_member,
                        m2.name AS to_member,
                        amount, status
                 FROM calculation_settlements s
                 JOIN room_members m1 ON s.from_member = m1.id
                 JOIN room_members m2 ON s.to_member = m2.id
                 WHERE snapshot_id=?`,
                [snapshot.id]
            );

            return res.json({
                frozen: true,
                total: Number(snapshot.total_amount),
                shares,
                settlements
            });
        }

        // -----------------------------
        // 4️⃣ Total expense
        // -----------------------------
        const [totalRows] = await db.execute(
            `SELECT SUM(amount) AS total_amount
             FROM expenses
             WHERE room_id = ?
               AND is_deleted = 0
               AND expense_date BETWEEN ? AND ?`,
            [room_id, start_date, end_date]
        );

        const total_amount = Number(totalRows[0].total_amount || 0);

        // -----------------------------
        // 5️⃣ FINAL OPTIMIZED SQL
        // -----------------------------
        const [members] = await db.execute(
            `WITH member_base AS (
                SELECT
                    rm.id AS member_id,
                    rm.name,
                    rm.is_active,
                    COALESCE(mr.excluded, 0) AS excluded,

                    -- ✅ Proper lifecycle clamp
                    GREATEST(
                        COALESCE(mr.start_date, rm.joined_at, ?),
                        rm.joined_at,
                        ?
                    ) AS start_date,

                    LEAST(
                        COALESCE(mr.end_date, rm.left_at, ?),
                        COALESCE(rm.left_at, ?),
                        ?
                    ) AS end_date,

                    mr.start_date AS s_date,
                    mr.end_date AS e_date

                FROM room_members rm
                LEFT JOIN member_month_rules mr
                    ON rm.id = mr.room_member_id
                    AND mr.month = ?
                    AND mr.year = ?

                WHERE rm.room_id = ?
                  -- ✅ ONLY show members inside lifecycle
                  AND rm.joined_at <= ?
                  AND (rm.left_at IS NULL OR rm.left_at >= ?)
            ),

            member_days AS (
                SELECT *,
                    CASE
                        WHEN excluded = 1
                             OR end_date < start_date
                             OR start_date > ?
                             OR end_date < ?
                        THEN 0
                        ELSE DATEDIFF(end_date, start_date) + 1
                    END AS days_present
                FROM member_base
            ),

            category_excluded_map AS (
                SELECT
                    mr.room_member_id AS member_id,
                    ce.category_id
                FROM member_month_rules mr
                JOIN member_month_category_exclusions ce
                    ON ce.rule_id = mr.id
                WHERE mr.month = ? AND mr.year = ?
            ),

            monthly_expenses AS (
                SELECT id, amount, category_id, expense_date, paid_by
                FROM expenses
                WHERE room_id = ?
                  AND is_deleted = 0
                  AND expense_date BETWEEN ? AND ?
            ),

            eligible_members AS (
                SELECT
                    e.id AS expense_id,
                    e.amount,
                    m.member_id,
                    m.days_present
                FROM monthly_expenses e
                JOIN member_days m
                    ON e.expense_date BETWEEN m.start_date AND m.end_date
                LEFT JOIN category_excluded_map cem
                    ON cem.member_id = m.member_id
                    AND cem.category_id = e.category_id
                WHERE m.excluded = 0
                  AND cem.category_id IS NULL
            ),

            expense_days AS (
                SELECT expense_id, SUM(days_present) AS total_days
                FROM eligible_members
                GROUP BY expense_id
            ),

            expense_split AS (
                SELECT
                    em.member_id,
                    ROUND(SUM(em.amount * em.days_present / ed.total_days), 2) AS share
                FROM eligible_members em
                JOIN expense_days ed USING (expense_id)
                GROUP BY em.member_id
            ),

            paid_amounts AS (
                SELECT paid_by AS member_id, SUM(amount) AS paid
                FROM expenses
                WHERE room_id = ?
                  AND is_deleted = 0
                  AND expense_date BETWEEN ? AND ?
                GROUP BY paid_by
            )

            SELECT
                m.member_id,
                m.name,
                COALESCE(p.paid, 0) AS paid,
                COALESCE(es.share, 0) AS owed,
                ROUND(COALESCE(p.paid, 0) - COALESCE(es.share, 0), 2) AS balance,
                m.days_present,
                m.excluded,

                CASE
                    WHEN (m.is_active = 0 OR m.s_date IS NOT NULL)
                    THEN m.start_date
                    ELSE NULL
                END AS start_date,

                CASE
                    WHEN (m.is_active = 0 OR m.e_date IS NOT NULL)
                    THEN m.end_date
                    ELSE NULL
                END AS end_date

            FROM member_days m
            LEFT JOIN expense_split es ON es.member_id = m.member_id
            LEFT JOIN paid_amounts p ON p.member_id = m.member_id`,
            [
                // start_date clamp
                start_date, start_date,
                end_date, end_date, end_date,

                // rules
                month, year,
                room_id,
                end_date, start_date,

                // days validation
                end_date, start_date,

                // category exclusions
                month, year,

                // expenses
                room_id, start_date, end_date,

                // paid
                room_id, start_date, end_date
            ]
        );

        // -----------------------------
        // 6️⃣ Type casting + tooltip
        // -----------------------------
        members.forEach(m => {
            let tooltip;

            if (m.excluded) {
                tooltip = `Excluded for ${new Date(start_date).toLocaleString('default', { month: 'long' })}`;
            } else if (m.days_present === total_days) {
                tooltip = 'Full month • All Categories';
            } else {
                tooltip = `${m.days_present} days active`;
            }

            m.member_id = Number(m.member_id);
            m.paid = Number(m.paid);
            m.owed = Number(m.owed);
            m.balance = Number(m.balance);
            m.days_present = Number(m.days_present);
            m.excluded = !!m.excluded;
            m.tooltip = tooltip;
        });
       
        // -----------------------------
        // 7️⃣ Settlement logic
        // -----------------------------
        let debtors = [];
        let creditors = [];

        members.forEach(m => {
            if (m.balance < -0.01) {
                debtors.push({
                    name: m.name,
                    amount: Math.abs(m.balance),
                    from_member: m.member_id
                });
            } else if (m.balance > 0.01) {
                creditors.push({
                    name: m.name,
                    amount: m.balance,
                    to_member: m.member_id
                });
            }
        });

        let settlements = [];
        let i = 0, j = 0;

        while (i < debtors.length && j < creditors.length) {
            let amount = Math.min(debtors[i].amount, creditors[j].amount);

            settlements.push({
                from: debtors[i].name,
                from_id: debtors[i].from_member,
                to: creditors[j].name,
                to_id: creditors[j].to_member,
                amount: Number(amount)
            });

            debtors[i].amount -= amount;
            creditors[j].amount -= amount;

            if (debtors[i].amount <= 0.01) i++;
            if (creditors[j].amount <= 0.01) j++;
        }

        // -----------------------------
        // 8️⃣ Response
        // -----------------------------
        const monthName = new Date(year, month - 1).toLocaleString('default', {
            month: 'long',
            year: 'numeric'
        });

        res.json({
            month: monthName,
            total_days,
            total_expense: total_amount,
            members,
            settlements,
            frozen: false
        });

    } catch (err) {
        console.error(err);
        res.status(500).json({ error: "Something went wrong" });
    }
};

exports.saveSnapshot = async (req, res) => {

    const connection = await db.getConnection(); 

    try {
        // -----------------------------
        // 1️⃣ Get Input
        // -----------------------------
        const {
            room_id,
            month,
            year,
            total,
            shares,
            settlements
        } = req.body;

        if (!room_id || !month || !year) {
            return res.status(400).json({ error: "Missing required fields" });
        }

        // -----------------------------
        // 2️⃣ Start Transaction
        // -----------------------------
        await connection.beginTransaction();

        // -----------------------------
        // 3️⃣ Insert / Update Snapshot
        // -----------------------------
        const [snapResult] = await connection.execute(
            `INSERT INTO calculation_snapshots
            (room_id, month, year, total_amount, frozen)
            VALUES (?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE
                total_amount = VALUES(total_amount),
                frozen = 1`,
            [room_id, month, year, total]
        );

        let snapshotId = snapResult.insertId;

        // If duplicate → fetch existing ID
        if (!snapshotId) {
            const [rows] = await connection.execute(
                `SELECT id FROM calculation_snapshots
                 WHERE room_id=? AND month=? AND year=?`,
                [room_id, month, year]
            );
            snapshotId = rows[0]?.id;
        }

        // -----------------------------
        // 4️⃣ Delete old data
        // -----------------------------
        await connection.execute(
            `DELETE FROM calculation_member_shares WHERE snapshot_id=?`,
            [snapshotId]
        );

        await connection.execute(
            `DELETE FROM calculation_settlements WHERE snapshot_id=?`,
            [snapshotId]
        );

        // -----------------------------
        // 5️⃣ Insert Shares (bulk optimized)
        // -----------------------------
        if (shares && shares.length > 0) {
            const shareValues = shares.map(s => [
                snapshotId,
                s.member_id,
                s.owed
            ]);

            await connection.query(
                `INSERT INTO calculation_member_shares
                (snapshot_id, member_id, share_amount)
                VALUES ?`,
                [shareValues]
            );
        }

        // -----------------------------
        // 6️⃣ Insert Settlements (bulk optimized)
        // -----------------------------
        if (settlements && settlements.length > 0) {
            const settlementValues = settlements.map(s => [
                snapshotId,
                s.from_id,
                s.to_id,
                s.amount
            ]);

            await connection.query(
                `INSERT INTO calculation_settlements
                (snapshot_id, from_member, to_member, amount)
                VALUES ?`,
                [settlementValues]
            );
        }

        // -----------------------------
        // 7️⃣ Commit
        // -----------------------------
        await connection.commit();

        res.json({ success: true });

    } catch (err) {
        await connection.rollback(); // 🔥 rollback on error
        console.error(err);
        res.status(500).json({ error: "Something went wrong" });
    } finally {
        connection.release(); // 🔑 always release
    }
};

exports.unfreezeSnapshot = async (req, res) => {
  
    const connection = await db.getConnection();

    try {
        // -----------------------------
        // 1️⃣ Input
        // -----------------------------
        const { room_id, month, year } = req.body;

        if (!room_id || !month || !year) {
            return res.status(400).json({
                error: "room_id, month, year required",
                success: false
            });
        }

        // -----------------------------
        // 2️⃣ CHECK SNAPSHOT
        // -----------------------------
        const [snapRows] = await connection.execute(
            `SELECT id FROM calculation_snapshots
             WHERE room_id=? AND month=? AND year=? AND frozen=1`,
            [room_id, month, year]
        );

        if (snapRows.length === 0) {
            return res.json({
                error: "No frozen snapshot found",
                success: false
            });
        }

        const snapshot_id = snapRows[0].id;

        // -----------------------------
        // 3️⃣ CHECK PAID SETTLEMENTS
        // -----------------------------
        // const [payRows] = await connection.execute(
        //     `SELECT COUNT(*) AS paid_count
        //      FROM calculation_settlements
        //      WHERE snapshot_id=? AND status='paid'`,
        //     [snapshot_id]
        // );

        // if (payRows[0].paid_count > 0) {
        //     return res.json({
        //         error: "Cannot unfreeze, settlements already paid",
        //         success: false
        //     });
        // }

        // -----------------------------
        // 4️⃣ START TRANSACTION
        // -----------------------------
        await connection.beginTransaction();

        // -----------------------------
        // 5️⃣ UNFREEZE SNAPSHOT
        // -----------------------------
        await connection.execute(
            `UPDATE calculation_snapshots
             SET frozen=0
             WHERE id=?`,
            [snapshot_id]
        );

        // -----------------------------
        // 6️⃣ CLEAN SNAPSHOT DATA
        // -----------------------------
        await connection.execute(
            `DELETE FROM calculation_member_shares
             WHERE snapshot_id=?`,
            [snapshot_id]
        );

        await connection.execute(
            `DELETE FROM calculation_settlements
             WHERE snapshot_id=?`,
            [snapshot_id]
        );

        // -----------------------------
        // 7️⃣ COMMIT
        // -----------------------------
        await connection.commit();

        res.json({ success: true });

    } catch (err) {
        await connection.rollback();
        console.error(err);

        res.status(500).json({
            error: "Something went wrong",
            success: false
        });
    } finally {
        connection.release();
    }
};

exports.getFrozenSnapshots = async (req, res) => {
    const connection = await db.getConnection();

    try {
        const room_id = req.params.room_id;

        if (!room_id) {
            return res.status(400).json({ error: "room_id is required" });
        }

        // 1️⃣ Get snapshots
        const [rows] = await connection.execute(
            `SELECT s.id, s.month, s.year, s.total_amount, s.frozen, s.created_at,
                    IFNULL(cs.is_settled, 0) as pending_count
             FROM calculation_snapshots s 
             LEFT JOIN (
                SELECT snapshot_id, COUNT(1) as is_settled
                FROM calculation_settlements 
                WHERE status = 'pending'
                GROUP BY snapshot_id
             ) cs ON s.id = cs.snapshot_id
             WHERE s.room_id=? AND s.frozen=1
             ORDER BY s.year DESC, s.month DESC`,
            [room_id]
        );

        // 2️⃣ Get ALL category breakdowns in ONE query
        const [categoryRows] = await connection.execute(
            `SELECT 
                e.room_id,
                YEAR(e.expense_date) as year,
                MONTH(e.expense_date) as month,
                c.name,
                SUM(e.amount) as amount
             FROM expenses e
             JOIN room_categories c ON c.id = e.category_id
             WHERE e.room_id = ?
             GROUP BY year, month, c.name, e.room_id`,
            [room_id]
        );

        // 3️⃣ Group category data
        const categoryMap = {};

        categoryRows.forEach(row => {
            const key = `${row.year}-${row.month}`;
            if (!categoryMap[key]) {
                categoryMap[key] = [];
            }
            categoryMap[key].push({
                name: row.name,
                amount: parseFloat(row.amount)
            });
        });

        // 4️⃣ Build final response
        const data = rows.map(row => {
            const monthName = new Date(row.year, row.month - 1, 1)
                .toLocaleString('default', { month: 'long' });

            const key = `${row.year}-${row.month}`;

            return {
                id: row.id,
                month: row.month,
                year: row.year,
                month_name: monthName,
                total_amount: parseFloat(row.total_amount),
                created_at: row.created_at,
                is_settled: row.pending_count === 0,
                category_breakdown: categoryMap[key] || []
            };
        });

        res.json(data);

    } catch (err) {
        console.error(err);
        res.status(500).json({ error: "Something went wrong" });
    } finally {
        connection.release();
    }
};

exports.markSettlementPaid = async (req, res) => {
 
    const connection = await db.getConnection();

    try {
        const { id } = req.body;

        if (!id) {
            return res.status(400).json({ error: "Settlement id is required" });
        }

        const [result] = await connection.execute(
            `UPDATE calculation_settlements
             SET status='paid'
             WHERE id=?`,
            [id]
        );

        res.json({ success: true });

    } catch (err) {
        console.error(err);
        res.status(500).json({ error: "Something went wrong" });
    } finally {
        connection.release();
    }
};

exports.getSnapshotSettlements = async (req, res) => {
   
    const connection = await db.getConnection();

    try {
        const snapshot_id = req.params.snapshotId;

        if (!snapshot_id) {
            return res.status(400).json({ error: "snapshotId is required" });
        }

        // -----------------------------
        // 1️⃣ Fetch settlements
        // -----------------------------
        const [rows] = await connection.execute(
            `SELECT 
                s.id,
                m1.name AS from_member,
                m2.name AS to_member,
                s.amount,
                s.status
             FROM calculation_settlements s
             JOIN room_members m1 ON s.from_member = m1.id
             JOIN room_members m2 ON s.to_member = m2.id
             WHERE s.snapshot_id=?`,
            [snapshot_id]
        );

        // -----------------------------
        // 2️⃣ Format result
        // -----------------------------
        const data = rows.map(row => ({
            id: row.id,
            from_member: row.from_member,
            to_member: row.to_member,
            amount: parseFloat(row.amount),
            paid: row.status === 'paid'
        }));

        res.json(data);

    } catch (err) {
        console.error(err);
        res.status(500).json({ error: "Something went wrong" });
    } finally {
        connection.release();
    }
};

function getDaysBetween(start, end) {
    const s = new Date(start);
    const e = new Date(end);
    return Math.floor((e - s) / (1000 * 60 * 60 * 24)) + 1;
}