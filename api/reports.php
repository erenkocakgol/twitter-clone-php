<?php
/**
 * Reports API Endpoints
 * SanatSepet Forum Platform
 */



require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/jwt.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../models/Report.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Post.php';
require_once __DIR__ . '/../models/Comment.php';

$report = new Report();
$user = new User();
$post = new Post();
$comment = new Comment();

switch ($requestMethod) {
    // =====================================================
    // POST /api/reports
    // =====================================================
    case 'POST':
        $authUser = JWT::requireAuth();
        
        Request::validate([
            'type' => 'required',
            'reason' => 'required|min:10|max:500'
        ]);
        
        $data = Request::body();
        
        // Validate report type
        $validTypes = ['spam', 'harassment', 'inappropriate', 'other'];
        if (!in_array($data['type'], $validTypes)) {
            Response::error('Geçersiz rapor tipi', 422);
        }
        
        // Must have at least one target
        if (!isset($data['user_id']) && !isset($data['post_id']) && !isset($data['comment_id'])) {
            Response::error('Raporlanacak içerik belirtilmedi', 422);
        }
        
        // Validate targets exist
        if (isset($data['user_id'])) {
            $targetUser = $user->findById($data['user_id']);
            if (!$targetUser) {
                Response::error('Kullanıcı bulunamadı', 404);
            }
            // Can't report yourself
            if ($data['user_id'] == $authUser['id']) {
                Response::error('Kendinizi raporlayamazsınız', 400);
            }
        }
        
        if (isset($data['post_id'])) {
            $targetPost = $post->findById($data['post_id']);
            if (!$targetPost) {
                Response::error('Post bulunamadı', 404);
            }
        }
        
        if (isset($data['comment_id'])) {
            $targetComment = $comment->findById($data['comment_id']);
            if (!$targetComment) {
                Response::error('Yorum bulunamadı', 404);
            }
        }
        
        // Create report
        $reportId = $report->create([
            'reporter_id' => $authUser['id'],
            'reported_user_id' => $data['user_id'] ?? null,
            'reported_post_id' => $data['post_id'] ?? null,
            'reported_comment_id' => $data['comment_id'] ?? null,
            'type' => $data['type'],
            'reason' => $data['reason']
        ]);
        
        Response::success(['id' => $reportId], 'Raporunuz alındı. İncelenecektir.', 201);
        break;
    
    default:
        Response::error('Method not allowed', 405);
}
