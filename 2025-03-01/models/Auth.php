<?php

class Auth {

    private $conn;

    public function __construct(PDO $db)
    {
        $this->conn = $db;
        $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /* =========================
       SIGNUP (With Transaction)
    ==========================*/
    public function signup($name, $phone, $password)
    {
        try {
            $this->conn->beginTransaction();

            // Check if email exists
            $check = $this->conn->prepare("SELECT id FROM users WHERE phone = ?");
            $check->execute([$phone]);

            if ($check->rowCount() > 0) {
                $this->conn->rollBack();
                return ["status" => false, "message" => "Mobile number already exists"];
            }

            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $this->conn->prepare(
                "INSERT INTO users (name, phone, password_hash) VALUES (?, ?, ?)"
            );

            $stmt->execute([$name, $phone, $hashedPassword]);

            $user_id = $this->conn->lastInsertId();

            $sql = "UPDATE room_members SET user_id = ?, name = ? WHERE phone = ?";

            $stmt = $this->conn->prepare($sql);

            $stmt->execute([$user_id,$name,$phone]);

            $this->conn->commit();

            return ["success" => true, "message" => "Signup successful","token" => md5(uniqid(rand() , true)),'user'=>[
                "id" => (int)$user_id,
                    "name" => $name,
                    "email" => null,
                    "phone" =>$phone
                ]
            ];

        } catch (Exception $e) {
            $this->conn->rollBack();
            return ["success" => false, "message" => $e->getMessage()];
        }
    }

    /* =========================
       LOGIN (With Transaction)
    ==========================*/
    public function login($phone, $password)
    {
        try {
            $this->conn->beginTransaction();

            $stmt = $this->conn->prepare("SELECT * FROM users WHERE phone = ?");
            $stmt->execute([$phone]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $this->conn->rollBack();
                return ["success" => false, "message" => "User not found"];
            }

            if (!password_verify($password, $user['password_hash'])) {
                $this->conn->rollBack();
                return ["success" => false, "message" => "Invalid password"];
            }

            $this->conn->commit();

            return [
                "success" => true,
                "message" => "Login successful",
                "token" => md5(uniqid(rand() , true)),
                "user" => [
                    "id" => $user['id'],
                    "name" => $user['name'],
                    "email" => $user['email'],
                    "phone" =>$user["phone"]
                ]
            ];

        } catch (Exception $e) {
            $this->conn->rollBack();
            return ["success" => false, "message" => $e->getMessage()];
        }
    }

    /* =========================
       FORGOT PASSWORD
    ==========================*/
    public function forgotPassword($email)
    {
        try {
            $this->conn->beginTransaction();

            $stmt = $this->conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);

            if ($stmt->rowCount() == 0) {
                $this->conn->rollBack();
                return ["status" => false, "message" => "Email not found"];
            }

            $token = bin2hex(random_bytes(32));
            $expiry = date("Y-m-d H:i:s", strtotime("+1 hour"));

            $update = $this->conn->prepare(
                "UPDATE users SET reset_token = ?, reset_token_expiry = ? WHERE email = ?"
            );

            $update->execute([$token, $expiry, $email]);

            $this->conn->commit();

            return [
                "status" => true,
                "message" => "Reset link generated",
                "token" => $token
            ];

        } catch (Exception $e) {
            $this->conn->rollBack();
            return ["status" => false, "message" => $e->getMessage()];
        }
    }

    /* =========================
       RESET PASSWORD
    ==========================*/
    public function resetPassword($token, $newPassword)
    {
        try {
            $this->conn->beginTransaction();

            $stmt = $this->conn->prepare(
                "SELECT id FROM users 
                 WHERE reset_token = ? 
                 AND reset_token_expiry > NOW() 
                 FOR UPDATE"
            );
            $stmt->execute([$token]);

            if ($stmt->rowCount() == 0) {
                $this->conn->rollBack();
                return ["status" => false, "message" => "Invalid or expired token"];
            }

            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

            $update = $this->conn->prepare(
                "UPDATE users 
                 SET password_hash = ?, 
                     reset_token = NULL, 
                     reset_token_expiry = NULL 
                 WHERE reset_token = ?"
            );

            $update->execute([$hashedPassword, $token]);

            $this->conn->commit();

            return ["status" => true, "message" => "Password reset successful"];

        } catch (Exception $e) {
            $this->conn->rollBack();
            return ["status" => false, "message" => $e->getMessage()];
        }
    }
}