<?php

class Database {
    private $conn;
    private $config;

    public function __construct() {
        $this->config = require __DIR__ . '/database.php';
    }

    public function connect() {
        if ($this->conn !== null) {
            return $this->conn; // reuse connection (singleton-like)
        }

        try {
            $dsn = "mysql:host={$this->config['host']};dbname={$this->config['dbname']};charset=utf8mb4";

            $this->conn = new PDO(
                $dsn,
                $this->config['user'],
                $this->config['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );

        } catch (PDOException $e) {
            http_response_code(500);

            // Avoid exposing DB errors in production
            if (!empty($this->config['debug'])) {
                echo json_encode(["error" => $e->getMessage()]);
            } else {
                echo json_encode(["error" => "Database connection failed"]);
            }

            exit;
        }

        return $this->conn;
    }
}