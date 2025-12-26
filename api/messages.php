<?php
/**
 * Messages API Endpoints
 * SanatSepet Forum Platform
 */

// Gerekli dosyalar
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../models/Message.php';
require_once __DIR__ . '/../models/User.php';

// Opsiyonel dosyalar
if (file_exists(__DIR__ . '/../config/jwt.php')) require_once __DIR__ . '/../config/jwt.php';

// Request Sınıfı Yardımcısı
if (!class_exists('Request')) {
    class Request {
        public static function query($key, $default = null) {
            return $_GET[$key] ?? $default;
        }
        public static function get($key, $default = null) {
            return $_GET[$key] ?? $_POST[$key] ?? $default;
        }
        public static function body() {
            $input = json_decode(file_get_contents('php://input'), true);
            return $input ?? $_POST;
        }
        public static function validate($rules) { return true; }
    }
}

$message = new Message();
$user = new User();

// =====================================================
// DEĞİŞKENLERİ TANIMLA (Hatanın Çözümü)
// =====================================================
$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];
$path = parse_url($requestUri, PHP_URL_PATH);
$segments = explode('/', trim($path, '/'));

// 'messages' kelimesinin konumunu bul
$messagesIndex = array_search('messages', $segments);
$action = null;
$param = null;

if ($messagesIndex !== false) {
    $action = $segments[$messagesIndex + 1] ?? null; // örn: conversations, 12, unread-count
    $param = $segments[$messagesIndex + 2] ?? null;  // örn: read
}

// Auth Kontrolü
$authUser = null;
if (class_exists('JWT')) {
    try {
        $authUser = JWT::getAuthenticatedUser();
    } catch (Exception $e) {
        // Token yoksa null
    }
}

try {
    switch ($action) {
        // =====================================================
        // GET /api/messages/conversations
        // =====================================================
        case 'conversations':
            if ($requestMethod !== 'GET') Response::error('Method not allowed', 405);
            $authUser = JWT::requireAuth();
            
            $page = (int) Request::query('page', 1);
            $perPage = (int) Request::query('per_page', 20);
            
            $results = $message->getConversationsFiltered($authUser['id'], $page, $perPage);
            Response::paginated($results['conversations'], $results['total'], $page, $perPage);
            break;
        
        // =====================================================
        // GET /api/messages/unread-count
        // =====================================================
        case 'unread-count':
            if ($requestMethod !== 'GET') Response::error('Method not allowed', 405);
            $authUser = JWT::requireAuth();
            
            $count = $message->getUnreadCount($authUser['id']);
            Response::success(['count' => $count]);
            break;
        
        // =====================================================
        // POST /api/messages/start
        // =====================================================
        case 'start':
            if ($requestMethod !== 'POST') Response::error('Method not allowed', 405);
            $authUser = JWT::requireAuth();
            
            $username = Request::get('username');
            if (!$username) Response::error('Username gerekli', 400);
            
            $targetUser = $user->findByUsername($username);
            if (!$targetUser) Response::error('Kullanıcı bulunamadı', 404);
            
            if ($targetUser['id'] == $authUser['id']) {
                Response::error('Kendinizle sohbet başlatamazsınız', 400);
            }
            
            // Engel kontrolü
            if ($user->isBlocked($authUser['id'], $targetUser['id'])) {
                Response::error('Bu kullanıcıyı engellediniz', 403);
            }
            if ($user->isBlocked($targetUser['id'], $authUser['id'])) {
                Response::error('Bu kullanıcı tarafından engellendiniz', 403);
            }
            
            $conversationId = $message->getOrCreateConversation($authUser['id'], $targetUser['id']);
            $message->unhideConversation($conversationId, $authUser['id']);
            $conversation = $message->getConversation($conversationId, $authUser['id']);
            
            Response::success($conversation, 'Sohbet başlatıldı');
            break;
        
        // =====================================================
        // Dynamic Routes (ID based) or Index
        // =====================================================
        default:
            // 1. Belirli bir sohbet işlemi (ID varsa)
            if ($action && is_numeric($action)) {
                $conversationId = (int) $action;
                
                // POST /{id}/read
                if ($param === 'read' && $requestMethod === 'POST') {
                    $authUser = JWT::requireAuth();
                    $message->markAsRead($conversationId, $authUser['id']);
                    Response::success(null, 'Okundu olarak işaretlendi');
                    break;
                }
                
                // DELETE /{id} (Gizle)
                if ($requestMethod === 'DELETE') {
                    $authUser = JWT::requireAuth();
                    $result = $message->hideConversation($conversationId, $authUser['id']);
                    
                    if (!$result) Response::error('Sohbet bulunamadı', 404);
                    Response::success(null, 'Sohbet gizlendi');
                    break;
                }
                
                // GET /{id} (Mesajları Getir)
                if ($requestMethod === 'GET') {
                    $authUser = JWT::requireAuth();
                    $page = (int) Request::query('page', 1);
                    $perPage = (int) Request::query('per_page', 50);
                    
                    $results = $message->getMessages($conversationId, $authUser['id'], $page, $perPage);
                    
                    if (!$results) Response::error('Sohbet bulunamadı', 404);
                    
                    // Otomatik okundu yap
                    $message->markAsRead($conversationId, $authUser['id']);
                    
                    Response::success([
                        'messages' => $results['messages'],
                        'conversation' => $results['conversation'],
                        'pagination' => [
                            'total' => $results['total'],
                            'page' => $page,
                            'per_page' => $perPage,
                            'has_more' => ($page * $perPage) < $results['total']
                        ]
                    ]);
                    break;
                }
            }
            
            // 2. Yeni Mesaj Gönder (POST /api/messages) - Action yoksa
            if (!$action && $requestMethod === 'POST') {
                $authUser = JWT::requireAuth();
                
                $data = Request::body();
                if (empty($data['recipient_id']) || empty($data['content'])) {
                    Response::error('Alıcı ve mesaj içeriği gerekli', 422);
                }
                
                $recipientId = (int) $data['recipient_id'];
                $recipient = $user->findById($recipientId);
                
                if (!$recipient) Response::error('Alıcı bulunamadı', 404);
                if ($recipientId == $authUser['id']) Response::error('Kendinize mesaj atamazsınız', 400);
                if ($recipient['is_banned']) Response::error('Bu kullanıcı yasaklı', 403);
                
                // Engel kontrolü
                if ($user->isBlocked($authUser['id'], $recipientId)) Response::error('Bu kullanıcıyı engellediniz', 403);
                if ($user->isBlocked($recipientId, $authUser['id'])) Response::error('Bu kullanıcı tarafından engellendiniz', 403);
                
                $newMessage = $message->send($authUser['id'], $recipientId, $data['content']);
                Response::success($newMessage, 'Mesaj gönderildi', 201);
                break;
            }
            
            Response::error('Endpoint bulunamadı', 404);
            break;
    }

} catch (Exception $e) {
    $code = $e->getCode();
    if (!is_int($code) || $code < 100 || $code > 599) {
        $code = 500;
    }
    Response::error($e->getMessage(), $code);
}