<?php
/**
 * API Router
 * SanatSepet Forum Platform
 */
 
date_default_timezone_set('Europe/Istanbul');
date('d.m.Y H:i:s');

// Geliştirme için hata raporlama (Prodüksiyonda kapatın)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Konfigürasyon dosyaları
require_once __DIR__ . '/../config/response.php';
// JWT opsiyonel olabilir, yoksa hata vermesin
if (file_exists(__DIR__ . '/../config/jwt.php')) {
    require_once __DIR__ . '/../config/jwt.php';
}

// CORS Headers
Response::cors();

// Preflight istekleri hemen yanıtla
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// İsteği Ayrıştır
$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Query string'i temizle ve path'i al
$basePath = '/api';
$uri = parse_url($requestUri, PHP_URL_PATH);

// Base path'i kaldır (örn: /api/users -> /users)
if (strpos($uri, $basePath) === 0) {
    $uri = substr($uri, strlen($basePath));
}
$uri = trim($uri, '/');
$segments = explode('/', $uri);

// Rota parametreleri
$resource = $segments[0] ?? ''; // users, posts, auth vb.
$action   = $segments[1] ?? null;
$param    = $segments[2] ?? null;


try {
    switch ($resource) {
        case 'auth':
            if (file_exists(__DIR__ . '/auth.php')) require_once __DIR__ . '/auth.php';
            else throw new Exception("Auth module not found", 500);
            break;
            
        case 'users':
            if (file_exists(__DIR__ . '/users.php')) require_once __DIR__ . '/users.php';
            else throw new Exception("Users module not found", 500);
            break;
            
        case 'posts':
            if (file_exists(__DIR__ . '/posts.php')) require_once __DIR__ . '/posts.php';
            else throw new Exception("Posts module not found", 500);
            break;
            
        case 'comments':
            if (file_exists(__DIR__ . '/comments.php')) require_once __DIR__ . '/comments.php';
            else throw new Exception("Comments module not found", 500);
            break;
            
        case 'follows':
            if (file_exists(__DIR__ . '/follows.php')) require_once __DIR__ . '/follows.php';
            else throw new Exception("Follows module not found", 500);
            break;
            
        case 'reports':
            if (file_exists(__DIR__ . '/reports.php')) require_once __DIR__ . '/reports.php';
            else throw new Exception("Reports module not found", 500);
            break;
            
        case 'upload':
            if (file_exists(__DIR__ . '/upload.php')) require_once __DIR__ . '/upload.php';
            else throw new Exception("Upload module not found", 500);
            break;
            
        case 'messages':
            if (file_exists(__DIR__ . '/messages.php')) require_once __DIR__ . '/messages.php';
            else throw new Exception("Messages module not found", 500);
            break;
            
        case 'settings':
            if (file_exists(__DIR__ . '/settings.php')) require_once __DIR__ . '/settings.php';
            else throw new Exception("Settings module not found", 500);
            break;
            
        case 'admin':
            if (file_exists(__DIR__ . '/admin.php')) require_once __DIR__ . '/admin.php';
            else throw new Exception("Admin module not found", 500);
            break;
            
        case 'health':
            Response::success(['status' => 'ok', 'timestamp' => date('c')]);
            break;
            
        default:
            // Ana dizin veya geçersiz endpoint
            Response::error('Endpoint bulunamadı', 404);
            break;
    }

} catch (Exception $e) {
    // === HATA DÜZELTMESİ: TÜR KONTROLÜ ===
    $code = $e->getCode();
    
    // SQL hataları string dönebilir veya kod geçersiz olabilir
    if (!is_int($code) || $code < 100 || $code > 599) {
        $code = 500;
    }
    
    Response::error($e->getMessage(), $code);
}