<?php
class Database {
    private $host;
    private $port;
    private $db_name;
    private $username;
    private $password;
    public $conn;

    public function __construct() {
        $this->host = $_ENV['DB_HOST'] ?? '127.0.0.1';
        $this->port = $_ENV['DB_PORT'] ?? '3306';
        $this->db_name = $_ENV['DB_NAME'] ?? 'quality_assurance_system';
        $this->username = $_ENV['DB_USER'] ?? 'root';
        $this->password = $_ENV['DB_PASS'] ?? '';
    }

    public function getConnection() {
        $this->conn = null;

        try {
            $dsn = "mysql:host=" . $this->host . ";port=" . $this->port . ";dbname=" . $this->db_name . ";charset=utf8mb4";
            $this->conn = new PDO($dsn, $this->username, $this->password);
            // Set error mode to throw exceptions
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // Return records as associative arrays by default
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch(PDOException $exception) {
            echo "Database Connection Error: " . htmlspecialchars($exception->getMessage()) . "<br><br>";
            echo "<strong>Attempted Connection Parameters:</strong><br>";
            echo "- Host: " . htmlspecialchars($this->host) . "<br>";
            echo "- Port: " . htmlspecialchars($this->port) . "<br>";
            echo "- User: " . htmlspecialchars($this->username) . "<br>";
            echo "- Database: " . htmlspecialchars($this->db_name) . "<br><br>";
            
            $envPath = __DIR__ . '/../.env';
            if (!file_exists($envPath)) {
                echo "<strong style='color: red;'>[DIAGNOSIS] Warning: .env file was NOT found in the application root directory:</strong><br>";
                echo "Expected location: <code>" . htmlspecialchars($envPath) . "</code><br>";
                echo "<em>If your site is hosted on InfinityFree, ensure the .env file is inside the <strong>htdocs/</strong> directory, not in the FTP root!</em><br>";
            } else {
                echo "<strong style='color: green;'>[DIAGNOSIS] .env file was successfully found!</strong><br>";
                echo "If the details above do not match your .env, verify your .env file syntax or check if there is an encoding/whitespace issue. Also verify that the MySQL databases in your InfinityFree control panel are created and matching these details.<br>";
            }
            die();
        }

        return $this->conn;
    }
}

