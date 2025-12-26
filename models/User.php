<?php
/**
 * User Model
 * SanatSepet Forum Platform
 */

require_once __DIR__ . '/../config/database.php';

class User {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Find user by ID
     */
    public function findById($id) {
        $stmt = $this->db->prepare("
            SELECT id, username, email, name, bio, avatar, cover, location, website, 
                   role, is_verified, is_banned, followers_count, following_count, posts_count, created_at
            FROM users WHERE id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Find user by username
     */
    public function findByUsername($username) {
        $stmt = $this->db->prepare("
            SELECT id, username, email, name, bio, avatar, cover, location, website, 
                   role, is_verified, is_banned, followers_count, following_count, posts_count, created_at
            FROM users WHERE username = ?
        ");
        $stmt->execute([$username]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Find user by email
     */
    public function findByEmail($email) {
        $stmt = $this->db->prepare("
            SELECT id, username, email, password, name, bio, avatar, cover, location, website, 
                   role, is_verified, is_banned, followers_count, following_count, posts_count, created_at
            FROM users WHERE email = ?
        ");
        $stmt->execute([$email]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Create new user
     */
    public function create($data) {
        $stmt = $this->db->prepare("
            INSERT INTO users (username, email, password, name) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['username'],
            $data['email'],
            password_hash($data['password'], PASSWORD_DEFAULT),
            $data['name']
        ]);
        return $this->db->lastInsertId();
    }
    
    /**
     * Update user profile
     */
    public function update($id, $data) {
        $fields = [];
        $values = [];
        
        foreach ($data as $key => $value) {
            $fields[] = "$key = ?";
            $values[] = $value;
        }
        
        $values[] = $id;
        
        $stmt = $this->db->prepare("
            UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?
        ");
        return $stmt->execute($values);
    }
    
    /**
     * Delete user
     */
    public function delete($id) {
        $stmt = $this->db->prepare("DELETE FROM users WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    /**
     * Verify password
     */
    public function verifyPassword($email, $password) {
        $user = $this->findByEmail($email);
        
        if (!$user) return false;
        
        if ($user['is_banned']) return 'banned';

        if (password_verify($password, $user['password'])) {
            return $user['id'];
        }
        
        return false;
    }

    /**
     * Get Auth Payload for JWT
     * Bu fonksiyon auth.php tarafından çağırılıyor.
     */
    public function getAuthPayload($userId) {
        $user = $this->findById($userId);
        
        if (!$user) {
            return null;
        }

        // Hassas verileri (şifre vb.) hariç tutarak token içine koyulacak veriyi dönüyoruz
        return [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'name' => $user['name'],
            'role' => $user['role'],
            'avatar' => $user['avatar'],
            'is_verified' => $user['is_verified']
        ];
    }
    
    /**
     * Search users
     */
    public function search($query, $limit = 10, $offset = 0) {
        $searchTerm = "%$query%";
        $stmt = $this->db->prepare("
            SELECT id, username, name, avatar, bio, followers_count
            FROM users 
            WHERE (username LIKE ? OR name LIKE ?) AND is_banned = 0
            ORDER BY followers_count DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$searchTerm, $searchTerm, $limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get suggested users (not followed, not blocked, ordered by followers)
     */
    public function getSuggested($userId, $limit = 5) {
        $stmt = $this->db->prepare("
            SELECT u.id, u.username, u.name, u.avatar, u.bio, u.followers_count
            FROM users u
            WHERE u.id != ?
              AND u.is_banned = 0
              AND u.id NOT IN (SELECT following_id FROM follows WHERE follower_id = ?)
              AND u.id NOT IN (SELECT blocked_id FROM user_blocks WHERE blocker_id = ?)
            ORDER BY u.followers_count DESC
            LIMIT ?
        ");
        $stmt->execute([$userId, $userId, $userId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Block a user
     */
    public function blockUser($blockerId, $blockedId) {
        // First unfollow each other
        $stmt = $this->db->prepare("DELETE FROM follows WHERE follower_id = ? AND following_id = ?");
        $stmt->execute([$blockerId, $blockedId]);
        $stmt->execute([$blockedId, $blockerId]);
        
        // Add block
        $stmt = $this->db->prepare("
            INSERT IGNORE INTO user_blocks (blocker_id, blocked_id) VALUES (?, ?)
        ");
        return $stmt->execute([$blockerId, $blockedId]);
    }
    
    /**
     * Unblock a user
     */
    public function unblockUser($blockerId, $blockedId) {
        $stmt = $this->db->prepare("
            DELETE FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?
        ");
        return $stmt->execute([$blockerId, $blockedId]);
    }
    
    /**
     * Check if user is blocked
     */
    public function isBlocked($blockerId, $blockedId) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?
        ");
        $stmt->execute([$blockerId, $blockedId]);
        return $stmt->fetchColumn() > 0;
    }
    
    /**
     * Get blocked users list
     */
    public function getBlockedUsers($userId, $page = 1, $perPage = 20) {
        $offset = ($page - 1) * $perPage;
        $stmt = $this->db->prepare("
            SELECT u.id, u.username, u.name, u.avatar
            FROM users u
            INNER JOIN user_blocks ub ON u.id = ub.blocked_id
            WHERE ub.blocker_id = ?
            ORDER BY ub.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$userId, $perPage, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Update username
     */
    public function updateUsername($id, $username) {
        // Check if username already exists
        $stmt = $this->db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmt->execute([$username, $id]);
        if ($stmt->fetch()) {
            throw new Exception('Bu kullanıcı adı zaten kullanılıyor');
        }
        
        return $this->update($id, ['username' => $username]);
    }
    
    /**
     * Update email
     */
    public function updateEmail($id, $email) {
        // Check if email already exists
        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $id]);
        if ($stmt->fetch()) {
            throw new Exception('Bu e-posta adresi zaten kullanılıyor');
        }
        
        // Update email and set is_verified to 0
        return $this->update($id, ['email' => $email, 'is_verified' => 0]);
    }
    
    /**
     * Update notification settings (JSON in user_settings table or user column)
     */
    public function updateNotificationSettings($id, $settings) {
        // Store in user_settings or as JSON column
        $stmt = $this->db->prepare("
            INSERT INTO user_settings (user_id, notification_settings) 
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE notification_settings = ?
        ");
        $json = json_encode($settings);
        return $stmt->execute([$id, $json, $json]);
    }
    
    /**
     * Get notification settings
     */
    public function getNotificationSettings($id) {
        $stmt = $this->db->prepare("
            SELECT notification_settings FROM user_settings WHERE user_id = ?
        ");
        $stmt->execute([$id]);
        $result = $stmt->fetchColumn();
        
        $defaults = [
            'email_new_follower' => true,
            'email_new_message' => true,
            'email_post_comment' => true,
            'email_post_star' => false,
            'email_post_repost' => false,
            'push_enabled' => true
        ];
        
        if ($result) {
            return array_merge($defaults, json_decode($result, true));
        }
        return $defaults;
    }
    
    /**
     * Update privacy settings
     */
    public function updatePrivacySettings($id, $settings) {
        $stmt = $this->db->prepare("
            INSERT INTO user_settings (user_id, privacy_settings) 
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE privacy_settings = ?
        ");
        $json = json_encode($settings);
        return $stmt->execute([$id, $json, $json]);
    }
    
    /**
     * Get privacy settings
     */
    public function getPrivacySettings($id) {
        $stmt = $this->db->prepare("
            SELECT privacy_settings FROM user_settings WHERE user_id = ?
        ");
        $stmt->execute([$id]);
        $result = $stmt->fetchColumn();
        
        $defaults = [
            'show_email' => false,
            'allow_messages' => true,
            'show_activity' => true
        ];
        
        if ($result) {
            return array_merge($defaults, json_decode($result, true));
        }
        return $defaults;
    }
    
    /**
     * Update password
     */
    public function updatePassword($id, $newPassword) {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        return $this->update($id, ['password' => $hash]);
    }
    
    /**
     * Check if username exists
     */
    public function usernameExists($username) {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $stmt->execute([$username]);
        return $stmt->fetchColumn() > 0;
    }
    
    /**
     * Check if email exists
     */
    public function emailExists($email) {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $stmt->execute([$email]);
        return $stmt->fetchColumn() > 0;
    }
    
    /**
     * Mark email as verified
     */
    public function verifyEmail($userId) {
        return $this->update($userId, ['is_verified' => 1]);
    }
}