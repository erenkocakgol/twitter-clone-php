<?php
/**
 * Message Model
 * SanatSepet Forum Platform
 */

require_once __DIR__ . '/../config/database.php';

class Message {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Get or create conversation between two users
     */
    public function getOrCreateConversation($userId1, $userId2) {
        // Order user IDs to ensure consistent lookup
        $participants = [$userId1, $userId2];
        sort($participants);
        
        // Check if conversation exists
        $stmt = $this->db->prepare("
            SELECT id FROM conversations 
            WHERE user1_id = ? AND user2_id = ?
        ");
        $stmt->execute($participants);
        $conversation = $stmt->fetch();
        
        if ($conversation) {
            return $conversation['id'];
        }
        
        // Create new conversation
        $stmt = $this->db->prepare("
            INSERT INTO conversations (user1_id, user2_id) VALUES (?, ?)
        ");
        $stmt->execute($participants);
        
        return $this->db->lastInsertId();
    }
    
    /**
     * Get conversation by ID with user details
     */
    public function getConversation($conversationId, $userId) {
        $stmt = $this->db->prepare("
            SELECT c.*,
                   CASE WHEN c.user1_id = ? THEN u2.id ELSE u1.id END as other_user_id,
                   CASE WHEN c.user1_id = ? THEN u2.username ELSE u1.username END as other_username,
                   CASE WHEN c.user1_id = ? THEN u2.name ELSE u1.name END as other_name,
                   CASE WHEN c.user1_id = ? THEN u2.avatar ELSE u1.avatar END as other_avatar
            FROM conversations c
            JOIN users u1 ON c.user1_id = u1.id
            JOIN users u2 ON c.user2_id = u2.id
            WHERE c.id = ? AND (c.user1_id = ? OR c.user2_id = ?)
        ");
        $stmt->execute([$userId, $userId, $userId, $userId, $conversationId, $userId, $userId]);
        return $stmt->fetch();
    }
    
    /**
     * Get user's conversations with last message
     */
    public function getConversations($userId, $page = 1, $perPage = 20) {
        $offset = ($page - 1) * $perPage;
        
        $stmt = $this->db->prepare("
            SELECT c.*,
                   CASE WHEN c.user1_id = :uid1 THEN u2.id ELSE u1.id END as other_user_id,
                   CASE WHEN c.user1_id = :uid2 THEN u2.username ELSE u1.username END as other_username,
                   CASE WHEN c.user1_id = :uid3 THEN u2.name ELSE u1.name END as other_name,
                   CASE WHEN c.user1_id = :uid4 THEN u2.avatar ELSE u1.avatar END as other_avatar,
                   m.content as last_message,
                   m.sender_id as last_message_sender_id,
                   m.created_at as last_message_at,
                   (SELECT COUNT(*) FROM messages 
                    WHERE conversation_id = c.id AND sender_id != :uid5 AND is_read = 0) as unread_count
            FROM conversations c
            JOIN users u1 ON c.user1_id = u1.id
            JOIN users u2 ON c.user2_id = u2.id
            LEFT JOIN messages m ON m.id = (
                SELECT id FROM messages 
                WHERE conversation_id = c.id 
                ORDER BY created_at DESC LIMIT 1
            )
            WHERE c.user1_id = :uid6 OR c.user2_id = :uid7
            ORDER BY COALESCE(m.created_at, c.created_at) DESC
            LIMIT :limit OFFSET :offset
        ");
        
        $stmt->bindValue(':uid1', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':uid2', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':uid3', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':uid4', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':uid5', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':uid6', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':uid7', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $conversations = $stmt->fetchAll();
        
        // Get total count
        $countStmt = $this->db->prepare("
            SELECT COUNT(*) FROM conversations 
            WHERE user1_id = ? OR user2_id = ?
        ");
        $countStmt->execute([$userId, $userId]);
        $total = $countStmt->fetchColumn();
        
        return ['conversations' => $conversations, 'total' => $total];
    }
    
    /**
     * Get messages in a conversation
     */
    public function getMessages($conversationId, $userId, $page = 1, $perPage = 50) {
        // Verify user is part of conversation
        $conv = $this->getConversation($conversationId, $userId);
        if (!$conv) {
            return null;
        }
        
        $offset = ($page - 1) * $perPage;
        
        $stmt = $this->db->prepare("
            SELECT m.*, u.username as sender_username, u.name as sender_name, u.avatar as sender_avatar
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            WHERE m.conversation_id = ?
            ORDER BY m.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$conversationId, $perPage, $offset]);
        $messages = $stmt->fetchAll();
        
        // Get total count
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM messages WHERE conversation_id = ?");
        $countStmt->execute([$conversationId]);
        $total = $countStmt->fetchColumn();
        
        // Reverse to show oldest first in UI
        $messages = array_reverse($messages);
        
        return ['messages' => $messages, 'total' => $total, 'conversation' => $conv];
    }
    
    /**
     * Send a message
     */
    public function send($senderId, $recipientId, $content) {
        // Get or create conversation
        $conversationId = $this->getOrCreateConversation($senderId, $recipientId);
        
        // Insert message
        $stmt = $this->db->prepare("
            INSERT INTO messages (conversation_id, sender_id, content)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$conversationId, $senderId, $content]);
        
        $messageId = $this->db->lastInsertId();
        
        // Update conversation timestamp
        $stmt = $this->db->prepare("UPDATE conversations SET updated_at = NOW() WHERE id = ?");
        $stmt->execute([$conversationId]);
        
        // Return the message with sender info
        return $this->getMessageById($messageId);
    }
    
    /**
     * Get single message
     */
    public function getMessageById($messageId) {
        $stmt = $this->db->prepare("
            SELECT m.*, u.username as sender_username, u.name as sender_name, u.avatar as sender_avatar
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            WHERE m.id = ?
        ");
        $stmt->execute([$messageId]);
        return $stmt->fetch();
    }
    
    /**
     * Mark messages as read
     */
    public function markAsRead($conversationId, $userId) {
        $stmt = $this->db->prepare("
            UPDATE messages 
            SET is_read = 1, read_at = NOW()
            WHERE conversation_id = ? AND sender_id != ? AND is_read = 0
        ");
        $stmt->execute([$conversationId, $userId]);
        return $stmt->rowCount();
    }
    
    /**
     * Get unread message count for user
     */
    public function getUnreadCount($userId) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM messages m
            JOIN conversations c ON m.conversation_id = c.id
            WHERE (c.user1_id = ? OR c.user2_id = ?) 
              AND m.sender_id != ? 
              AND m.is_read = 0
        ");
        $stmt->execute([$userId, $userId, $userId]);
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * Delete a message (soft delete)
     */
    public function deleteMessage($messageId, $userId) {
        $stmt = $this->db->prepare("
            UPDATE messages SET is_deleted = 1 
            WHERE id = ? AND sender_id = ?
        ");
        $stmt->execute([$messageId, $userId]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Hide a conversation for a user (soft delete)
     */
    public function hideConversation($conversationId, $userId) {
        // Verify user is part of the conversation
        $conv = $this->getConversation($conversationId, $userId);
        if (!$conv) {
            return false;
        }
        
        $stmt = $this->db->prepare("
            INSERT IGNORE INTO conversation_hidden (conversation_id, user_id) VALUES (?, ?)
        ");
        $stmt->execute([$conversationId, $userId]);
        return true;
    }
    
    /**
     * Unhide a conversation for a user
     */
    public function unhideConversation($conversationId, $userId) {
        $stmt = $this->db->prepare("
            DELETE FROM conversation_hidden WHERE conversation_id = ? AND user_id = ?
        ");
        $stmt->execute([$conversationId, $userId]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Get user's conversations excluding hidden ones
     */
    public function getConversationsFiltered($userId, $page = 1, $perPage = 20) {
        $offset = ($page - 1) * $perPage;
        
        $stmt = $this->db->prepare("
            SELECT c.*,
                   CASE WHEN c.user1_id = :uid1 THEN u2.id ELSE u1.id END as other_user_id,
                   CASE WHEN c.user1_id = :uid2 THEN u2.username ELSE u1.username END as other_username,
                   CASE WHEN c.user1_id = :uid3 THEN u2.name ELSE u1.name END as other_name,
                   CASE WHEN c.user1_id = :uid4 THEN u2.avatar ELSE u1.avatar END as other_avatar,
                   m.content as last_message,
                   m.sender_id as last_message_sender_id,
                   m.created_at as last_message_at,
                   (SELECT COUNT(*) FROM messages 
                    WHERE conversation_id = c.id AND sender_id != :uid5 AND is_read = 0) as unread_count
            FROM conversations c
            JOIN users u1 ON c.user1_id = u1.id
            JOIN users u2 ON c.user2_id = u2.id
            LEFT JOIN messages m ON m.id = (
                SELECT id FROM messages 
                WHERE conversation_id = c.id 
                ORDER BY created_at DESC LIMIT 1
            )
            LEFT JOIN conversation_hidden ch ON ch.conversation_id = c.id AND ch.user_id = :uid8
            WHERE (c.user1_id = :uid6 OR c.user2_id = :uid7) AND ch.id IS NULL
            ORDER BY COALESCE(m.created_at, c.created_at) DESC
            LIMIT :limit OFFSET :offset
        ");
        
        $stmt->bindValue(':uid1', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':uid2', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':uid3', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':uid4', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':uid5', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':uid6', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':uid7', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':uid8', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $conversations = $stmt->fetchAll();
        
        // Get total count (excluding hidden)
        $countStmt = $this->db->prepare("
            SELECT COUNT(*) FROM conversations c
            LEFT JOIN conversation_hidden ch ON ch.conversation_id = c.id AND ch.user_id = ?
            WHERE (c.user1_id = ? OR c.user2_id = ?) AND ch.id IS NULL
        ");
        $countStmt->execute([$userId, $userId, $userId]);
        $total = $countStmt->fetchColumn();
        
        return ['conversations' => $conversations, 'total' => $total];
    }
}
