<?php
/**
 * Database Configuration
 * SanatSepet Forum Platform
 */

class Database {
    private static $instance = null;
    private $connection;
    
    // Database credentials - loaded from environment or fallback to defaults
    private $host;
    private $dbname;
    private $username;
    private $password;
    private $charset = 'utf8mb4';
    
    private function __construct() {
        // Load from environment variables or use defaults
        $this->host = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? 'localhost');
        $this->dbname = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? 'kri94alsofcomtr_sanatsepet');
        $this->username = getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? 'kri94alsofcomtr_admin');
        $this->password = getenv('DB_PASS') ?: ($_ENV['DB_PASS'] ?? 'Yenieren_2536');
        
        try {
            $dsn = "mysql:host={$this->host};dbname={$this->dbname};charset={$this->charset}";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];
            
            $this->connection = new PDO($dsn, $this->username, $this->password, $options);
        } catch (PDOException $e) {
            throw new Exception("Veritabanı bağlantısı başarısız: " . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    // Prevent cloning
    private function __clone() {}
    
    // Prevent unserialization
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}
