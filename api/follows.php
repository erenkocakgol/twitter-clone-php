<?php
/**
 * Follows API Endpoints
 * SanatSepet Forum Platform
 */

// Gerekli dosyalar
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Follow.php';

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

$user = new User();
$follow = new Follow();

// =====================================================
// DEĞİŞKENLERİ TANIMLA (Hatanın Çözümü)
// =====================================================
$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];
$path = parse_url($requestUri, PHP_URL_PATH);
$segments = explode('/', trim($path, '/')); // URL parçaları

// 'follows' kelimesinin konumunu bul
$followsIndex = array_search('follows', $segments);
$action = null;
$param = null;

if ($followsIndex !== false) {
    $action = $segments[$followsIndex + 1] ?? null; // Genellikle {username}
    $param = $segments[$followsIndex + 2] ?? null;  // Genellikle {followers/following}
}

// Auth Kontrolü (Hata vermeden)
$authUser = null;
if (class_exists('JWT')) {
    try {
        $authUser = JWT::getAuthenticatedUser();
    } catch (Exception $e) {
        // Token yoksa null kalsın
    }
}

try {
    switch ($requestMethod) {
        // =====================================================
        // GET /api/follows/{username}/followers
        // GET /api/follows/{username}/following
        // =====================================================
        case 'GET':
            if (!$action) {
                Response::error('Username gerekli', 400);
            }
            
            $username = $action;
            $userData = $user->findByUsername($username);
            
            if (!$userData) {
                Response::error('Kullanıcı bulunamadı', 404);
            }
            
            $page = (int) Request::query('page', 1);
            $perPage = (int) Request::query('per_page', 20);
            
            // Takipçileri Getir
            if ($param === 'followers') {
                $results = $follow->getFollowers($userData['id'], $page, $perPage);
                
                // Add isFollowing flag if authenticated
                if ($authUser) {
                    foreach ($results['users'] as &$u) {
                        $u['is_following'] = $follow->isFollowing($authUser['id'], $u['id']);
                        $u['is_self'] = ($authUser['id'] == $u['id']);
                    }
                }
                
                Response::paginated($results['users'], $results['total'], $page, $perPage);
                break;
            }
            
            // Takip Edilenleri Getir
            if ($param === 'following') {
                $results = $follow->getFollowing($userData['id'], $page, $perPage);
                
                // Add isFollowing flag if authenticated
                if ($authUser) {
                    foreach ($results['users'] as &$u) {
                        $u['is_following'] = $follow->isFollowing($authUser['id'], $u['id']);
                        $u['is_self'] = ($authUser['id'] == $u['id']);
                    }
                }
                
                Response::paginated($results['users'], $results['total'], $page, $perPage);
                break;
            }
            
            Response::error('Geçersiz endpoint (followers veya following belirtin)', 400);
            break;
        
        // =====================================================
        // POST /api/follows/{username} (Takip Et)
        // =====================================================
        case 'POST':
            if (!$authUser) Response::error('Oturum gerekli', 401);
            
            if (!$action) {
                Response::error('Username gerekli', 400);
            }
            
            $username = $action;
            $targetUser = $user->findByUsername($username);
            
            if (!$targetUser) {
                Response::error('Kullanıcı bulunamadı', 404);
            }
            
            // Can't follow yourself
            if ($targetUser['id'] == $authUser['id']) {
                Response::error('Kendinizi takip edemezsiniz', 400);
            }
            
            $result = $follow->follow($authUser['id'], $targetUser['id']);
            
            Response::success([
                'is_following' => true,
                'followers_count' => $follow->getFollowersCount($targetUser['id'])
            ], $result ? 'Takip edildi' : 'Zaten takip ediliyor');
            break;
        
        // =====================================================
        // DELETE /api/follows/{username} (Takipten Çık)
        // =====================================================
        case 'DELETE':
            if (!$authUser) Response::error('Oturum gerekli', 401);
            
            if (!$action) {
                Response::error('Username gerekli', 400);
            }
            
            $username = $action;
            $targetUser = $user->findByUsername($username);
            
            if (!$targetUser) {
                Response::error('Kullanıcı bulunamadı', 404);
            }
            
            $follow->unfollow($authUser['id'], $targetUser['id']);
            
            Response::success([
                'is_following' => false,
                'followers_count' => $follow->getFollowersCount($targetUser['id'])
            ], 'Takipten çıkıldı');
            break;
        
        default:
            Response::error('Method not allowed', 405);
    }

} catch (Exception $e) {
    // Hata kodunu integer olarak düzelt
    $code = $e->getCode();
    if (!is_int($code) || $code < 100 || $code > 599) {
        $code = 500;
    }
    Response::error($e->getMessage(), $code);
}