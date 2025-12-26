<?php
/**
 * Users API Endpoints
 * SanatSepet Forum Platform
 */

// Gerekli dosyalar (index.php'de çağrılmış olabilir ama garantiye alalım)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../models/User.php';

// Follow modeli varsa dahil et
if (file_exists(__DIR__ . '/../models/Follow.php')) {
    require_once __DIR__ . '/../models/Follow.php';
    $follow = new Follow();
} else {
    $follow = null;
}

// Request Sınıfı Yardımcısı (Eğer sistemde yoksa burada tanımlıyoruz)
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

// URL Ayrıştırma (Index.php'den gelen değişkenlere güvenmek yerine tekrar ayrıştırıyoruz, daha güvenli)
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);
// /api/users/search -> parts: [api, users, search]
$urlParts = explode('/', trim($path, '/'));

// Index.php /api/ kısmını handle etmiş olsa bile burada tam path'e bakıyoruz
// urlParts yapısı sunucu konfigürasyonuna göre değişebilir, genellikle:
// 0: api, 1: users, 2: action, 3: subaction
// VEYA direkt: 0: users, 1: action (htaccess'e bağlı)

// Basit bir tespit mantığı: 'users' kelimesinden sonrasını al
$action = null;
$subAction = null;

$usersIndex = array_search('users', $urlParts);
if ($usersIndex !== false) {
    $action = $urlParts[$usersIndex + 1] ?? null;
    $subAction = $urlParts[$usersIndex + 2] ?? null;
}

// Auth Kontrolü
$authUser = null;
if (class_exists('JWT')) {
    try {
        $authUser = JWT::getAuthenticatedUser();
    } catch (Exception $e) { Response::error("User auth edilemedi!"); }
}

try {
    switch ($_SERVER['REQUEST_METHOD']) {
        // =====================================================
        // GET
        // =====================================================
        case 'GET':
            // 1. ARAMA
            if ($action === 'search') {
                $query = Request::query('q', '');
                $page = (int) Request::query('page', 1);
                $perPage = (int) Request::query('per_page', 10);
                
                if (empty($query)) {
                    Response::success([]);
                    break;
                }

                $results = $user->search($query, $perPage, ($page - 1) * $perPage);
                
                if ($authUser && $follow) {
                    foreach ($results as &$u) {
                        $u['is_following'] = $follow->isFollowing($authUser['id'], $u['id']);
                    }
                }
                Response::success($results);
                break;
            }
            
            // 2. ÖNERİLENLER
            if ($action === 'suggested') {
                if (!$authUser) Response::error('Oturum gerekli', 401);
                $limit = min((int) Request::query('limit', 5), 20);
                $results = $user->getSuggested($authUser['id'], $limit);
                Response::success($results);
                break;
            }
            
            // 3. ENGELLENENLER
            if ($action === 'blocked') {
                if (!$authUser) Response::error('Oturum gerekli', 401);
                $page = (int) Request::query('page', 1);
                $results = $user->getBlockedUsers($authUser['id'], $page);
                Response::success($results);
                break;
            }
            
            // 4. BİLDİRİM AYARLARI
            if ($action === 'notifications') {
                if (!$authUser) Response::error('Oturum gerekli', 401);
                $settings = $user->getNotificationSettings($authUser['id']);
                Response::success($settings);
                break;
            }
            
            // 5. GİZLİLİK AYARLARI
            if ($action === 'privacy') {
                if (!$authUser) Response::error('Oturum gerekli', 401);
                $settings = $user->getPrivacySettings($authUser['id']);
                Response::success($settings);
                break;
            }
            
            // 6. PROFİL GÖRÜNTÜLEME (Son kontrol)
            if ($action) {
                $userData = $user->findByUsername($action);
                
                if (!$userData) {
                    Response::error('Kullanıcı bulunamadı', 404);
                }
                
                if ($authUser) {
                    if ($follow) $userData['is_following'] = $follow->isFollowing($authUser['id'], $userData['id']);
                    $userData['is_self'] = ($authUser['id'] == $userData['id']);
                    $userData['is_blocked'] = $user->isBlocked($authUser['id'], $userData['id']);
                    $userData['is_blocked_by'] = $user->isBlocked($userData['id'], $authUser['id']);
                }
                
                unset($userData['password']);
                Response::success($userData);
                break;
            }
            
            Response::error('Parametre eksik', 400);
            break;
            
        // =====================================================
        // POST
        // =====================================================
        case 'POST':
            // ENGELLEME
            if ($subAction === 'block') {
                if (!$authUser) Response::error('Oturum gerekli', 401);
                $targetUser = $user->findByUsername($action);
                
                if (!$targetUser) Response::error('Kullanıcı bulunamadı', 404);
                if ($targetUser['id'] == $authUser['id']) Response::error('Kendinizi engelleyemezsiniz', 400);
                
                $user->blockUser($authUser['id'], $targetUser['id']);
                Response::success(null, 'Kullanıcı engellendi');
                break;
            }
            Response::error('Endpoint bulunamadı', 404);
            break;
            
        // =====================================================
        // PUT
        // =====================================================
        case 'PUT':
            if (!$authUser) Response::error('Oturum gerekli', 401);
            
            if ($action === 'profile') {
                $data = Request::body();
                $allowed = ['name', 'bio', 'location', 'website', 'avatar', 'cover'];
                $updateData = array_intersect_key($data, array_flip($allowed));
                
                if (isset($updateData['name']) && strlen($updateData['name']) < 2) {
                    Response::error('İsim en az 2 karakter olmalıdır', 422);
                }
                
                $user->update($authUser['id'], $updateData);
                $updatedUser = $user->findById($authUser['id']);
                unset($updatedUser['password']);
                Response::success($updatedUser, 'Profil güncellendi');
                break;
            }
            
            if ($action === 'username') {
                $data = Request::body();
                $newUsername = trim($data['username'] ?? '');
                if (strlen($newUsername) < 3) Response::error('Kullanıcı adı çok kısa', 422);
                $user->updateUsername($authUser['id'], $newUsername);
                $updatedUser = $user->findById($authUser['id']);
                unset($updatedUser['password']);
                Response::success($updatedUser, 'Kullanıcı adı güncellendi');
                break;
            }
            
            if ($action === 'email') {
                $data = Request::body();
                $newEmail = trim($data['email'] ?? '');
                if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) Response::error('Geçersiz e-posta', 422);
                $user->updateEmail($authUser['id'], $newEmail);
                Response::success(null, 'E-posta güncellendi');
                break;
            }
            
            if ($action === 'notifications') {
                $user->updateNotificationSettings($authUser['id'], Request::body());
                Response::success(null, 'Ayarlar güncellendi');
                break;
            }

            if ($action === 'privacy') {
                $user->updatePrivacySettings($authUser['id'], Request::body());
                Response::success(null, 'Ayarlar güncellendi');
                break;
            }
            
            Response::error('Endpoint bulunamadı', 404);
            break;
            
        // =====================================================
        // DELETE
        // =====================================================
        case 'DELETE':
            if (!$authUser) Response::error('Oturum gerekli', 401);
            
            if ($action === 'account') {
                $pass = Request::get('password');
                if (!$pass || !$user->verifyPassword($authUser['email'], $pass)) {
                    Response::error('Şifre hatalı', 401);
                }
                $user->delete($authUser['id']);
                Response::success(null, 'Hesap silindi');
                break;
            }
            
            if ($subAction === 'block') {
                $targetUser = $user->findByUsername($action);
                if (!$targetUser) Response::error('Kullanıcı bulunamadı', 404);
                $user->unblockUser($authUser['id'], $targetUser['id']);
                Response::success(null, 'Engel kaldırıldı');
                break;
            }
            
            Response::error('Endpoint bulunamadı', 404);
            break;
            
        default:
            Response::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    // Burada da string hata kodu kontrolü yapıyoruz
    $code = $e->getCode();
    if (!is_int($code) || $code < 100 || $code > 599) {
        $code = 500;
    }
    Response::error($e->getMessage(), $code);
}