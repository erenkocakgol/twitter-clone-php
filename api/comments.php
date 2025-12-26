<?php
/**
 * Comments API Endpoints
 * SanatSepet Forum Platform
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/jwt.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../models/Comment.php';

$comment = new Comment();

// Get current user if authenticated
$authUser = JWT::getAuthenticatedUser();

switch ($requestMethod) {
    // =====================================================
    // GET /api/comments/{id}
    // GET /api/comments/{id}/replies
    // =====================================================
    case 'GET':
        if (!$action) {
            Response::error('Comment ID gerekli', 400);
        }
        
        $commentId = (int) $action;
        
        // Get replies
        if ($param === 'replies') {
            $replies = $comment->getReplies($commentId);
            Response::success($replies);
        }
        
        // Get single comment
        $commentData = $comment->findById($commentId);
        
        if (!$commentData) {
            Response::error('Yorum bulunamadı', 404);
        }
        
        Response::success($commentData);
        break;
    
    // =====================================================
    // DELETE /api/comments/{id}
    // =====================================================
    case 'DELETE':
        $authUser = JWT::requireAuth();
        
        if (!$action) {
            Response::error('Comment ID gerekli', 400);
        }
        
        $commentId = (int) $action;
        $commentData = $comment->findById($commentId);
        
        if (!$commentData) {
            Response::error('Yorum bulunamadı', 404);
        }
        
        // Check ownership or admin
        if ($commentData['user_id'] != $authUser['id'] && $authUser['role'] !== 'admin') {
            Response::error('Bu işlem için yetkiniz yok', 403);
        }
        
        $comment->delete($commentId, $authUser['id'], $authUser['role'] === 'admin');
        Response::success(null, 'Yorum silindi');
        break;
    
    default:
        Response::error('Method not allowed', 405);
}
