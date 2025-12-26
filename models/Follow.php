<?php
/**
 * Follow Model
 * SanatSepet Forum Platform
 */

require_once __DIR__ . '/../config/database.php';

class Follow {
    private $db;
    private $table = 'follows';
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Follow a user
     */
    public function follow($followerId, $followingId) {
        if ($followerId === $followingId) {
            throw new Exception('Kendinizi takip edemezsiniz');
        }
        
        try {
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare("
                INSERT INTO {$this->table} (follower_id, following_id)
                VALUES (?, ?)
            ");
            $stmt->execute([$followerId, $followingId]);
            
            // Update follower's following_count
            $stmt = $this->db->prepare("UPDATE users SET following_count = following_count + 1 WHERE id = ?");
            $stmt->execute([$followerId]);
            
            // Update followed user's followers_count
            $stmt = $this->db->prepare("UPDATE users SET followers_count = followers_count + 1 WHERE id = ?");
            $stmt->execute([$followingId]);
            
            $this->db->commit();
            return true;
        } catch (PDOException $e) {
            $this->db->rollBack();
            // Already following
            return false;
        }
    }
    
    /**
     * Unfollow a user
     */
    public function unfollow($followerId, $followingId) {
        try {
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare("
                DELETE FROM {$this->table}
                WHERE follower_id = ? AND following_id = ?
            ");
            $stmt->execute([$followerId, $followingId]);
            
            if ($stmt->rowCount() > 0) {
                // Update follower's following_count
                $stmt = $this->db->prepare("UPDATE users SET following_count = GREATEST(following_count - 1, 0) WHERE id = ?");
                $stmt->execute([$followerId]);
                
                // Update unfollowed user's followers_count
                $stmt = $this->db->prepare("UPDATE users SET followers_count = GREATEST(followers_count - 1, 0) WHERE id = ?");
                $stmt->execute([$followingId]);
                
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
     * Check if following
     */
    public function isFollowing($followerId, $followingId) {
        $stmt = $this->db->prepare("
            SELECT 1 FROM {$this->table}
            WHERE follower_id = ? AND following_id = ?
        ");
        $stmt->execute([$followerId, $followingId]);
        return $stmt->fetch() !== false;
    }
    
    /**
     * Get followers of a user
     */
    public function getFollowers($userId, $page = 1, $perPage = 20) {
        $offset = ($page - 1) * $perPage;
        
        $stmt = $this->db->prepare("
            SELECT u.id, u.username, u.name, u.avatar, u.bio, u.followers_count
            FROM {$this->table} f
            JOIN users u ON f.follower_id = u.id
            WHERE f.following_id = ?
            ORDER BY f.created_at DESC
            LIMIT ? OFFSET ?
        ");
        
        $stmt->execute([$userId, $perPage, $offset]);
        $followers = $stmt->fetchAll();
        
        // Get total count
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM {$this->table} WHERE following_id = ?");
        $countStmt->execute([$userId]);
        $total = $countStmt->fetchColumn();
        
        return ['users' => $followers, 'total' => $total];
    }
    
    /**
     * Get users that a user is following
     */
    public function getFollowing($userId, $page = 1, $perPage = 20) {
        $offset = ($page - 1) * $perPage;
        
        $stmt = $this->db->prepare("
            SELECT u.id, u.username, u.name, u.avatar, u.bio, u.followers_count
            FROM {$this->table} f
            JOIN users u ON f.following_id = u.id
            WHERE f.follower_id = ?
            ORDER BY f.created_at DESC
            LIMIT ? OFFSET ?
        ");
        
        $stmt->execute([$userId, $perPage, $offset]);
        $following = $stmt->fetchAll();
        
        // Get total count
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM {$this->table} WHERE follower_id = ?");
        $countStmt->execute([$userId]);
        $total = $countStmt->fetchColumn();
        
        return ['users' => $following, 'total' => $total];
    }
    
    /**
     * Get followers count for a user
     */
    public function getFollowersCount($userId) {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$this->table} WHERE following_id = ?");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }
}
