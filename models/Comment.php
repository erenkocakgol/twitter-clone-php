<?php
/**
 * Comment Model
 * SanatSepet Forum Platform
 */

require_once __DIR__ . '/../config/database.php';

class Comment {
    private $db;
    private $table = 'comments';
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Get comment by ID
     */
    public function findById($id) {
        $stmt = $this->db->prepare("
            SELECT c.*, u.username, u.name as user_name, u.avatar as user_avatar
            FROM {$this->table} c
            JOIN users u ON c.user_id = u.id
            WHERE c.id = ? AND c.is_deleted = 0
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    
    /**
     * Get comments for a post
     */
    public function getByPostId($postId, $page = 1, $perPage = 20) {
        $offset = ($page - 1) * $perPage;
        
        $stmt = $this->db->prepare("
            SELECT c.*, u.username, u.name as user_name, u.avatar as user_avatar
            FROM {$this->table} c
            JOIN users u ON c.user_id = u.id
            WHERE c.post_id = ? AND c.parent_id IS NULL AND c.is_deleted = 0
            ORDER BY c.created_at DESC
            LIMIT ? OFFSET ?
        ");
        
        $stmt->execute([$postId, $perPage, $offset]);
        $comments = $stmt->fetchAll();
        
        // Get replies for each comment
        foreach ($comments as &$comment) {
            $comment['replies'] = $this->getReplies($comment['id']);
        }
        
        // Get total count
        $countStmt = $this->db->prepare("
            SELECT COUNT(*) FROM {$this->table}
            WHERE post_id = ? AND parent_id IS NULL AND is_deleted = 0
        ");
        $countStmt->execute([$postId]);
        $total = $countStmt->fetchColumn();
        
        return ['comments' => $comments, 'total' => $total];
    }
    
    /**
     * Get replies for a comment
     */
    public function getReplies($commentId) {
        $stmt = $this->db->prepare("
            SELECT c.*, u.username, u.name as user_name, u.avatar as user_avatar
            FROM {$this->table} c
            JOIN users u ON c.user_id = u.id
            WHERE c.parent_id = ? AND c.is_deleted = 0
            ORDER BY c.created_at ASC
        ");
        
        $stmt->execute([$commentId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Create new comment
     */
    public function create($data) {
        try {
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare("
                INSERT INTO {$this->table} (post_id, user_id, parent_id, content)
                VALUES (?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $data['post_id'],
                $data['user_id'],
                $data['parent_id'] ?? null,
                $data['content']
            ]);
            
            $commentId = $this->db->lastInsertId();
            
            // Update post's comments_count
            $stmt = $this->db->prepare("UPDATE posts SET comments_count = comments_count + 1 WHERE id = ?");
            $stmt->execute([$data['post_id']]);
            
            $this->db->commit();
            
            return $this->findById($commentId);
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Delete comment (soft delete)
     */
    public function delete($id, $userId = null, $isAdmin = false) {
        try {
            $this->db->beginTransaction();
            
            // First get the comment to find post_id
            $comment = $this->findById($id);
            if (!$comment) {
                $this->db->rollBack();
                return false;
            }
            
            if ($isAdmin) {
                $stmt = $this->db->prepare("UPDATE {$this->table} SET is_deleted = 1 WHERE id = ?");
                $stmt->execute([$id]);
            } else {
                $stmt = $this->db->prepare("UPDATE {$this->table} SET is_deleted = 1 WHERE id = ? AND user_id = ?");
                $stmt->execute([$id, $userId]);
            }
            
            if ($stmt->rowCount() > 0) {
                // Update post's comments_count
                $stmt = $this->db->prepare("UPDATE posts SET comments_count = GREATEST(comments_count - 1, 0) WHERE id = ?");
                $stmt->execute([$comment['post_id']]);
                
                $this->db->commit();
                return true;
            }
            
            $this->db->rollBack();
            return false;
        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }
    
    /**
     * Get stats (admin)
     */
    public function getStats() {
        $stmt = $this->db->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as new_this_week,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) as new_today
            FROM {$this->table}
            WHERE is_deleted = 0
        ");
        return $stmt->fetch();
    }
    
    /**
     * Get total comment count
     */
    public function getCount() {
        $stmt = $this->db->query("SELECT COUNT(*) FROM {$this->table} WHERE is_deleted = 0");
        return (int)$stmt->fetchColumn();
    }
}
