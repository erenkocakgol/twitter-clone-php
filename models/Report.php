<?php
/**
 * Report Model
 * SanatSepet Forum Platform
 */

require_once __DIR__ . '/../config/database.php';

class Report {
    private $db;
    private $table = 'reports';
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Create new report
     */
    public function create($data) {
        $stmt = $this->db->prepare("
            INSERT INTO {$this->table} (reporter_id, post_id, comment_id, user_id, reason, description)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $data['reporter_id'],
            $data['post_id'] ?? null,
            $data['comment_id'] ?? null,
            $data['user_id'] ?? null,
            $data['reason'],
            $data['description'] ?? null
        ]);
        
        return $this->db->lastInsertId();
    }
    
    /**
     * Get report by ID
     */
    public function findById($id) {
        $stmt = $this->db->prepare("
            SELECT r.*, 
                   reporter.username as reporter_username,
                   reporter.name as reporter_name,
                   reporter.avatar as reporter_avatar,
                   post_author.username as post_author_username,
                   reported_user.username as reported_username
            FROM {$this->table} r
            JOIN users reporter ON r.reporter_id = reporter.id
            LEFT JOIN posts p ON r.post_id = p.id
            LEFT JOIN users post_author ON p.user_id = post_author.id
            LEFT JOIN users reported_user ON r.user_id = reported_user.id
            WHERE r.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    
    /**
     * Get all reports (admin)
     */
    public function getAll($page = 1, $perPage = 20, $status = null) {
        $offset = ($page - 1) * $perPage;
        $params = [];
        $whereClause = "1=1";
        
        if ($status) {
            $whereClause .= " AND r.status = ?";
            $params[] = $status;
        }
        
        // Count
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM {$this->table} r WHERE $whereClause");
        $countStmt->execute($params);
        $total = $countStmt->fetchColumn();
        
        // Get reports
        $params[] = $perPage;
        $params[] = $offset;
        
        $stmt = $this->db->prepare("
            SELECT r.*, 
                   reporter.username as reporter_username,
                   reporter.name as reporter_name,
                   reporter.avatar as reporter_avatar,
                   p.content as post_content,
                   c.content as comment_content,
                   post_author.username as post_author_username,
                   reported_user.username as reported_username
            FROM {$this->table} r
            JOIN users reporter ON r.reporter_id = reporter.id
            LEFT JOIN posts p ON r.post_id = p.id
            LEFT JOIN comments c ON r.comment_id = c.id
            LEFT JOIN users post_author ON p.user_id = post_author.id
            LEFT JOIN users reported_user ON r.user_id = reported_user.id
            WHERE $whereClause
            ORDER BY r.created_at DESC
            LIMIT ? OFFSET ?
        ");
        
        $stmt->execute($params);
        $reports = $stmt->fetchAll();
        
        return ['reports' => $reports, 'total' => $total];
    }
    
    /**
     * Update report status
     */
    public function updateStatus($id, $status, $adminId, $notes = null) {
        $stmt = $this->db->prepare("
            UPDATE {$this->table}
            SET status = ?, resolved_by = ?, admin_notes = ?, resolved_at = NOW()
            WHERE id = ?
        ");
        
        return $stmt->execute([$status, $adminId, $notes, $id]);
    }
    
    /**
     * Get stats (admin)
     */
    public function getStats() {
        $stmt = $this->db->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'reviewed' THEN 1 ELSE 0 END) as reviewed,
                SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved,
                SUM(CASE WHEN status = 'dismissed' THEN 1 ELSE 0 END) as dismissed,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) as new_today
            FROM {$this->table}
        ");
        return $stmt->fetch();
    }
    
    /**
     * Get pending reports count
     */
    public function getPendingCount() {
        $stmt = $this->db->query("SELECT COUNT(*) FROM {$this->table} WHERE status = 'pending'");
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * Get recent reports
     */
    public function getRecent($limit = 5) {
        $stmt = $this->db->prepare("
            SELECT r.id, r.type, r.reason, r.status, r.created_at,
                   reporter.username as reporter_username,
                   reporter.avatar as reporter_avatar
            FROM {$this->table} r
            JOIN users reporter ON r.reporter_id = reporter.id
            ORDER BY r.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }
    
    /**
     * Update report
     */
    public function update($id, $data) {
        $fields = [];
        $values = [];
        
        $allowedFields = ['status', 'admin_notes', 'resolved_by', 'resolved_at'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                $values[] = $data[$field];
            }
        }
        
        if (empty($fields)) return false;
        
        $values[] = $id;
        
        $stmt = $this->db->prepare("
            UPDATE {$this->table} 
            SET " . implode(', ', $fields) . "
            WHERE id = ?
        ");
        
        return $stmt->execute($values);
    }
}
