<?php
/**
 * Auth API Endpoints
 * SanatSepet Forum Platform
 */

// Gerekli dosyalar
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../models/User.php';

// Opsiyonel dosyalar (Hata vermemesi için kontrol ediyoruz)
if (file_exists(__DIR__ . '/../config/jwt.php')) require_once __DIR__ . '/../config/jwt.php';
if (file_exists(__DIR__ . '/../config/mail.php')) require_once __DIR__ . '/../config/mail.php';
if (file_exists(__DIR__ . '/../models/Token.php')) require_once __DIR__ . '/../models/Token.php';

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

// Mailer Yardımcısı (Eğer yoksa hata vermemesi için dummy class)
if (!class_exists('Mailer')) {
    class Mailer {
        public static function sendVerificationEmail($email, $name, $token) { return true; }
        public static function sendPasswordResetEmail($email, $name, $token) { return true; }
    }
}

$user = new User();
// Token sınıfı yoksa null ata
$token = class_exists('Token') ? new Token() : null;

// =====================================================
// DEĞİŞKENLERİ TANIMLA (Hatanın Çözümü)
// =====================================================
$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];
$path = parse_url($requestUri, PHP_URL_PATH);
$urlParts = explode('/', trim($path, '/'));

// 'auth' kelimesinden sonrasını 'action' olarak al
$action = null;
$authIndex = array_search('auth', $urlParts);
if ($authIndex !== false) {
    $action = $urlParts[$authIndex + 1] ?? null;
}

try {
    switch ($action) {
        // =====================================================
        // POST /api/auth/register
        // =====================================================
        case 'register':
            if ($requestMethod !== 'POST') Response::error('Method not allowed', 405);
            
            $data = Request::body();
            
            // Validasyonlar
            if (empty($data['username']) || empty($data['email']) || empty($data['password'])) {
                Response::error('Lütfen tüm alanları doldurun', 422);
            }

            // Check username
            if ($user->usernameExists($data['username'])) {
                Response::error('Bu kullanıcı adı zaten kullanılıyor', 409);
            }
            
            // Check email
            if ($user->emailExists($data['email'])) {
                Response::error('Bu e-posta adresi zaten kayıtlı', 409);
            }
            
            // Validate password simple check
            if (strlen($data['password']) < 6) {
                Response::error('Şifre en az 6 karakter olmalıdır', 422);
            }
            
            // Create user
            $userId = $user->create($data);
            
            // Token işlemleri (eğer Token sınıfı varsa)
            if ($token) {
                $verificationToken = $token->createVerificationToken($userId);
                Mailer::sendVerificationEmail($data['email'], $data['name'], $verificationToken);
            }
            
            Response::success(null, 'Hesabınız oluşturuldu. Giriş yapabilirsiniz.', 201);
            break;
        
        // =====================================================
        // POST /api/auth/login
        // =====================================================
        case 'login':
            if ($requestMethod !== 'POST') Response::error('Method not allowed', 405);
            
            $data = Request::body();
            
            if (empty($data['email']) || empty($data['password'])) {
                Response::error('E-posta ve şifre gereklidir', 422);
            }
            
            // Verify credentials
            $result = $user->verifyPassword($data['email'], $data['password']);
            
            if ($result === false) {
                Response::error('E-posta veya şifre hatalı', 401);
            }
            
            if ($result === 'banned') {
                Response::error('Hesabınız askıya alınmış', 403);
            }
            
            $userId = $result;
            $userData = $user->findById($userId);
            
            // Generate JWT
            $authPayload = $user->getAuthPayload($userId);
            
            // JWT sınıfı varsa token üret
            $jwtToken = class_exists('JWT') ? JWT::generate($authPayload) : 'dummy-token';
            
            Response::success([
                'token' => $jwtToken,
                'user' => $authPayload
            ], 'Giriş başarılı');
            break;
        
        // =====================================================
        // POST /api/auth/logout
        // =====================================================
        case 'logout':
            Response::success(null, 'Çıkış yapıldı');
            break;
        
        // =====================================================
        // GET /api/auth/me
        // =====================================================
        case 'me':
            if ($requestMethod !== 'GET') Response::error('Method not allowed', 405);
            
            $authUser = JWT::requireAuth();
            $userData = $user->findById($authUser['id']);
            
            if (!$userData) Response::error('Kullanıcı bulunamadı', 404);
            
            // Password temizle
            unset($userData['password']);
            
            Response::success($userData);
            break;
        
        // =====================================================
        // POST /api/auth/verify-email
        // =====================================================
        case 'verify-email':
            if ($requestMethod !== 'POST') Response::error('Method not allowed', 405);
            if (!$token) Response::error('Token sistemi aktif değil', 500);
            
            $verifyToken = Request::get('token');
            if (!$verifyToken) Response::error('Token gerekli', 400);
            
            $userId = $token->verifyEmailToken($verifyToken);
            if (!$userId) Response::error('Geçersiz bağlantı', 400);
            
            $user->verifyEmail($userId);
            Response::success(null, 'E-posta doğrulandı');
            break;
        
        // =====================================================
        // POST /api/auth/forgot-password
        // =====================================================
        case 'forgot-password':
            if ($requestMethod !== 'POST') Response::error('Method not allowed', 405);
            
            $email = Request::get('email');
            $userData = $user->findByEmail($email);
            
            if ($userData && $token) {
                $resetToken = $token->createPasswordResetToken($userData['id']);
                Mailer::sendPasswordResetEmail($email, $userData['name'], $resetToken);
            }
            
            Response::success(null, 'Eğer kayıtlıysa, şifre sıfırlama bağlantısı gönderildi');
            break;
        
        // =====================================================
        // POST /api/auth/reset-password
        // =====================================================
        case 'reset-password':
            if ($requestMethod !== 'POST') Response::error('Method not allowed', 405);
            if (!$token) Response::error('Sistem hatası', 500);
            
            $data = Request::body();
            if (empty($data['token']) || empty($data['password'])) Response::error('Eksik bilgi', 422);
            
            $userId = $token->verifyPasswordResetToken($data['token']);
            if (!$userId) Response::error('Geçersiz bağlantı', 400);
            
            $user->updatePassword($userId, $data['password']);
            $token->markPasswordResetUsed($data['token']);
            
            Response::success(null, 'Şifreniz güncellendi');
            break;
        
        // =====================================================
        // POST /api/auth/change-password
        // =====================================================
        case 'change-password':
            if ($requestMethod !== 'POST') Response::error('Method not allowed', 405);
            
            $authUser = JWT::requireAuth();
            $data = Request::body();
            
            $result = $user->verifyPassword($authUser['email'], $data['current_password'] ?? '');
            if (!$result || $result === 'banned') Response::error('Mevcut şifre hatalı', 401);
            
            $user->updatePassword($authUser['id'], $data['new_password']);
            Response::success(null, 'Şifreniz güncellendi');
            break;
        
        default:
            Response::error('Endpoint bulunamadı', 404);
    }

} catch (Exception $e) {
    $code = $e->getCode();
    if (!is_int($code) || $code < 100 || $code > 599) {
        $code = 500;
    }
    Response::error($e->getMessage(), $code);
}