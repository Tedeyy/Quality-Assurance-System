<?php
require_once __DIR__ . '/env.php';

class ResponsesDatabase {
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $port;
    public $conn;

    public function __construct() {
        $this->host = $_ENV['RESPONSES_DB_HOST'] ?? '127.0.0.1';
        $this->db_name = $_ENV['RESPONSES_DB_NAME'] ?? 'activity_evaluation_responses';
        $this->username = $_ENV['RESPONSES_DB_USER'] ?? 'root';
        $this->password = $_ENV['RESPONSES_DB_PASS'] ?? '';
        $this->port = $_ENV['RESPONSES_DB_PORT'] ?? '3306';
    }

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";port=" . $this->port . ";dbname=" . $this->db_name, $this->username, $this->password);
            $this->conn->exec("set names utf8");
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $exception) {
            // If DB doesn't exist, try to create it
            try {
                $temp_conn = new PDO("mysql:host=" . $this->host . ";port=" . $this->port, $this->username, $this->password);
                $temp_conn->exec("CREATE DATABASE IF NOT EXISTS " . $this->db_name);
                $this->conn = new PDO("mysql:host=" . $this->host . ";port=" . $this->port . ";dbname=" . $this->db_name, $this->username, $this->password);
                $this->conn->exec("set names utf8");
                $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } catch(PDOException $e) {
                error_log("Connection error: " . $e->getMessage());
            }
        }
        return $this->conn;
    }
}
?>
