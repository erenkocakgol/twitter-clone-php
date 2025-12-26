<?php
/**
 * Token Model - Email Verification & Password Reset
 * SanatSepet Forum Platform
 */

require_once __DIR__ . '/../config/database.php';

class Token {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Create email verification token
     */
    public function createVerificationToken($userId) {
        // Delete existing tokens
        $this->deleteVerificationTokens($userId);
        
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        $stmt = $this->db->prepare("
            INSERT INTO email_verifications (user_id, token, expires_at)
            VALUES (?, ?, ?)
        ");
        
        $stmt->execute([$userId, $token, $expiresAt]);
        
        return $token;
    }
    
    /**
     * Verify email token
     */
    public function verifyEmailToken($token) {
        $stmt = $this->db->prepare("
            SELECT user_id FROM email_verifications 
            WHERE token = ? AND expires_at > NOW()
        ");
        
        $stmt->execute([$token]);
        $result = $stmt->fetch();
        
        if (!$result) return false;
        
        // Delete the token
        $this->deleteVerificationToken($token);
        
        return $result['user_id'];
    }
    
    /**
     * Delete verification token
     */
    public function deleteVerificationToken($token) {
        $stmt = $this->db->prepare("DELETE FROM email_verifications WHERE token = ?");
        $stmt->execute([$token]);
    }
    
    /**
     * Delete all verification tokens for user
     */
    public function deleteVerificationTokens($userId) {
        $stmt = $this->db->prepare("DELETE FROM email_verifications WHERE user_id = ?");
        $stmt->execute([$userId]);
    }
    
    /**
     * Create password reset token
     */
    public function createPasswordResetToken($userId) {
        // Delete existing tokens
        $this->deletePasswordResetTokens($userId);
        
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        $stmt = $this->db->prepare("
            INSERT INTO password_resets (user_id, token, expires_at)
            VALUES (?, ?, ?)
        ");
        
        $stmt->execute([$userId, $token, $expiresAt]);
        
        return $token;
    }
    
    /**
     * Verify password reset token
     */
    public function verifyPasswordResetToken($token) {
        $stmt = $this->db->prepare("
            SELECT user_id FROM password_resets 
            WHERE token = ? AND expires_at > NOW() AND used = 0
        ");
        
        $stmt->execute([$token]);
        $result = $stmt->fetch();
        
        if (!$result) return false;
        
        return $result['user_id'];
    }
    
    /**
     * Mark password reset token as used
     */
    public function markPasswordResetUsed($token) {
        $stmt = $this->db->prepare("UPDATE password_resets SET used = 1 WHERE token = ?");
        $stmt->execute([$token]);
    }
    
    /**
     * Delete all password reset tokens for user
     */
    public function deletePasswordResetTokens($userId) {
        $stmt = $this->db->prepare("DELETE FROM password_resets WHERE user_id = ?");
        $stmt->execute([$userId]);
    }
    
    /**
     * Cleanup expired tokens
     */
    public function cleanupExpired() {
        $this->db->exec("DELETE FROM email_verifications WHERE expires_at < NOW()");
        $this->db->exec("DELETE FROM password_resets WHERE expires_at < NOW()");
    }
}
