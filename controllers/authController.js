const db = require('../config/db');
const bcrypt = require('bcryptjs');
const crypto = require('crypto');

/* =========================
   SIGNUP
=========================*/
exports.signup = async (req, res) => {
  const { name, phone, password } = req.body;

  const conn = await db.getConnection();

  try {
    await conn.beginTransaction();

    const [check] = await conn.execute(
      "SELECT id FROM users WHERE phone = ?",
      [phone]
    );

    if (check.length > 0) {
      await conn.rollback();
      return res.json({ success: false, message: "Mobile number already exists" });
    }

    const hashedPassword = await bcrypt.hash(password, 10);

    const [result] = await conn.execute(
      "INSERT INTO users (name, phone, password_hash) VALUES (?, ?, ?)",
      [name, phone, hashedPassword]
    );

    const user_id = result.insertId;

    await conn.execute(
      "UPDATE room_members SET user_id = ?, name = ? WHERE phone = ?",
      [user_id, name, phone]
    );

    await conn.commit();

    return res.json({
      success: true,
      message: "Signup successful",
      token: crypto.randomBytes(16).toString("hex"),
      user: {
        id: user_id,
        name,
        email: null,
        phone
      }
    });

  } catch (err) {
    await conn.rollback();
    return res.json({ success: false, message: err.message });
  } finally {
    conn.release();
  }
};


/* =========================
   LOGIN
=========================*/
exports.login = async (req, res) => {
  const { phone, password } = req.body;

  const conn = await db.getConnection();

  try {
    await conn.beginTransaction();

    const [rows] = await conn.execute(
      "SELECT * FROM users WHERE phone = ?",
      [phone]
    );

    if (rows.length === 0) {
      await conn.rollback();
      return res.json({ success: false, message: "User not found" });
    }

    const user = rows[0];

    const valid = await bcrypt.compare(password, user.password_hash);

    if (!valid) {
      await conn.rollback();
      return res.json({ success: false, message: "Invalid password" });
    }

    await conn.commit();

    return res.json({
      success: true,
      message: "Login successful",
      token: crypto.randomBytes(16).toString("hex"),
      user: {
        id: user.id,
        name: user.name,
        email: user.email,
        phone: user.phone
      }
    });

  } catch (err) {
    await conn.rollback();
    return res.json({ success: false, message: err.message });
  } finally {
    conn.release();
  }
};


/* =========================
   FORGOT PASSWORD
=========================*/
exports.forgotPassword = async (req, res) => {
  const { email } = req.body;

  const conn = await db.getConnection();

  try {
    await conn.beginTransaction();

    const [rows] = await conn.execute(
      "SELECT id FROM users WHERE email = ?",
      [email]
    );

    if (rows.length === 0) {
      await conn.rollback();
      return res.json({ success: false, message: "Email not found" });
    }

    const token = crypto.randomBytes(32).toString("hex");
    const expiry = new Date(Date.now() + 60 * 60 * 1000); // 1 hour

    await conn.execute(
      "UPDATE users SET reset_token = ?, reset_token_expiry = ? WHERE email = ?",
      [token, expiry, email]
    );

    await conn.commit();

    return res.json({
      success: true,
      message: "Reset link generated",
      token
    });

  } catch (err) {
    await conn.rollback();
    return res.json({ success: false, message: err.message });
  } finally {
    conn.release();
  }
};


/* =========================
   RESET PASSWORD
=========================*/
exports.resetPassword = async (req, res) => {
  const { token, newPassword } = req.body;

  const conn = await db.getConnection();

  try {
    await conn.beginTransaction();

    const [rows] = await conn.execute(
      `SELECT id FROM users 
       WHERE reset_token = ? 
       AND reset_token_expiry > NOW() 
       FOR UPDATE`,
      [token]
    );

    if (rows.length === 0) {
      await conn.rollback();
      return res.json({ success: false, message: "Invalid or expired token" });
    }

    const hashedPassword = await bcrypt.hash(newPassword, 10);

    await conn.execute(
      `UPDATE users 
       SET password_hash = ?, 
           reset_token = NULL, 
           reset_token_expiry = NULL 
       WHERE reset_token = ?`,
      [hashedPassword, token]
    );

    await conn.commit();

    return res.json({ success: true, message: "Password reset successful" });

  } catch (err) {
    await conn.rollback();
    return res.json({ success: false, message: err.message });
  } finally {
    conn.release();
  }
};