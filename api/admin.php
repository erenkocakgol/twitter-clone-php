<?php
/**
 * Admin API Endpoints
 * SanatSepet Forum Platform
 * * Frontend Uyumluluğu:
 * - AdminLayout.jsx
 * - Dashboard.jsx
 * - UserManagement.jsx
 * - PostManagement.jsx
 * - ReportManagement.jsx
 * - SiteSettings.jsx, SEOSettings.jsx, AdsenseSettings.jsx
 */

// Hataları Production'da gizle
error_reporting(E_ALL);
ini_set('display_errors', 0);

// =====================================================
// 1. YAPILANDIRMA VE HEADERLAR
// =====================================================

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, X-Auth-Token");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Dosya yolları (api/admin.php konumuna göre)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../models/User.php';

if (file_exists(__DIR__ . '/../config/jwt.php')) {
    require_once __DIR__ . '/../config/jwt.php';
}

// =====================================================
// 2. YARDIMCI FONKSİYONLAR
// =====================================================

// Ayarları veritabanından çekmek için yardımcı fonksiyon
function getSystemSettings($db, $keys = []) {
    try {
        $checkTable = $db->query("SHOW TABLES LIKE 'system_settings'");
        if ($checkTable->rowCount() === 0) return [];

        $sql = "SELECT setting_key, setting_value FROM system_settings";
        if (!empty($keys)) {
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $sql .= " WHERE setting_key IN ($placeholders)";
        }
        
        $stmt = $db->prepare($sql);
        if (!empty($keys)) $stmt->execute($keys);
        else $stmt->execute();
        
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        foreach ($settings as $k => $v) {
            if (in_array($v, ['true', 'false'])) {
                $settings[$k] = filter_var($v, FILTER_VALIDATE_BOOLEAN);
            }
        }
        return $settings;
    } catch (Exception $e) {
        return [];
    }
}

function saveSystemSettings($db, $data) {
    $db->exec("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    $sql = "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
    $stmt = $db->prepare($sql);

    foreach ($data as $key => $val) {
        if (is_bool($val)) $val = $val ? 'true' : 'false';
        if (is_array($val) || is_object($val)) $val = json_encode($val);
        $stmt->execute([$key, $val]);
    }
}

// =====================================================
// 3. BAŞLATMA VE YETKİLENDİRME
// =====================================================

$db = Database::getInstance()->getConnection();
$userModel = new User();

// URL Router Logic
$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];
$path = parse_url($requestUri, PHP_URL_PATH);
$segments = explode('/', trim($path, '/'));

$adminIndex = array_search('admin', $segments);
if ($adminIndex === false) $adminIndex = array_search('admin.php', $segments);

$endpoint = $adminIndex !== false ? ($segments[$adminIndex + 1] ?? 'dashboard') : 'dashboard';
$param = $adminIndex !== false ? ($segments[$adminIndex + 2] ?? null) : null;

// Auth Check
$authUser = null;
if (class_exists('JWT')) {
    try {
        if (method_exists('JWT', 'requireAdmin')) {
            $authUser = JWT::requireAdmin();
        } else {
            $authUser = JWT::requireAuth();
            $isAdmin = (isset($authUser['role']) && $authUser['role'] === 'admin') || 
                       (isset($authUser['is_admin']) && ((int)$authUser['is_admin'] === 1));
            
            if (!$isAdmin) Response::error('Yetkisiz erişim', 403);
        }
    } catch (Exception $e) {
        Response::error('Oturum hatası: ' . $e->getMessage(), 401);
    }
}

// =====================================================
// 4. ENDPOINT MANTIĞI
// =====================================================

try {
    switch ($endpoint) {
        
        // -------------------------------------------------
        // DASHBOARD
        // -------------------------------------------------
        case 'dashboard':
            if ($requestMethod !== 'GET') Response::error('Method not allowed', 405);
            
            $stats = [
                'total_users' => $db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
                'total_posts' => $db->query("SELECT COUNT(*) FROM posts")->fetchColumn(),
                'total_comments' => $db->query("SELECT COUNT(*) FROM comments")->fetchColumn(),
                'today_visits' => $db->query("SELECT COUNT(*) FROM users WHERE DATE(updated_at) = CURDATE()")->fetchColumn(),
                'active_users' => $db->query("SELECT COUNT(*) FROM users WHERE updated_at >= NOW() - INTERVAL 7 DAY")->fetchColumn(),
                'new_users_today' => $db->query("SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()")->fetchColumn(),
                'pending_reports' => $db->query("SELECT COUNT(*) FROM reports WHERE status = 'pending'")->fetchColumn(),
            ];

            $recentUsers = $db->query("SELECT id, name, username, avatar, created_at FROM users ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
            
            $recentPosts = [];
            try {
                $recentPosts = $db->query("
                    SELECT p.id, p.title, p.content, p.created_at, u.username 
                    FROM posts p 
                    JOIN users u ON p.user_id = u.id 
                    ORDER BY p.created_at DESC LIMIT 5
                ")->fetchAll(PDO::FETCH_ASSOC);
            } catch(Exception $e){}

            $recentReports = [];
            try {
                $recentReports = $db->query("SELECT type, reason, status, created_at FROM reports ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
            } catch(Exception $e){}

            Response::success([
                'stats' => $stats,
                'recent_activity' => [
                    'new_users' => $recentUsers,
                    'new_posts' => $recentPosts,
                    'new_reports' => $recentReports
                ]
            ]);
            break;

        // -------------------------------------------------
        // USERS
        // -------------------------------------------------
        case 'users':
            if ($requestMethod === 'GET') {
                $page = (int) Request::query('page', 1);
                $perPage = (int) Request::query('per_page', 15);
                $search = Request::query('q', null);
                $filter = Request::query('filter', 'all');
                $offset = ($page - 1) * $perPage;

                // DÜZELTME: is_admin sütunu kaldırıldı, role sütunu eklendi
                $sql = "SELECT id, username, email, name, avatar, role, is_banned, is_verified, created_at FROM users WHERE 1=1";
                $countSql = "SELECT COUNT(*) FROM users WHERE 1=1";
                $params = [];

                if ($search) {
                    $cond = " AND (username LIKE ? OR email LIKE ? OR name LIKE ?)";
                    $sql .= $cond; $countSql .= $cond;
                    $term = "%$search%";
                    $params = [$term, $term, $term];
                }

                if ($filter === 'admin') {
                    // DÜZELTME: is_admin yerine role kontrolü
                    $sql .= " AND role = 'admin'"; 
                    $countSql .= " AND role = 'admin'";
                } elseif ($filter === 'banned') {
                    $sql .= " AND is_banned = 1"; 
                    $countSql .= " AND is_banned = 1";
                }

                $sql .= " ORDER BY created_at DESC LIMIT $perPage OFFSET $offset";
                
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $stmtCount = $db->prepare($countSql);
                $stmtCount->execute($params);
                $total = (int)$stmtCount->fetchColumn();

                foreach ($users as &$u) {
                    $u['id'] = (int)$u['id'];
                    // DÜZELTME: Frontend için sanal is_admin alanı oluşturma
                    $u['is_admin'] = ($u['role'] === 'admin') ? 1 : 0;
                    $u['is_banned'] = (int)$u['is_banned'];
                    $u['is_verified'] = (int)$u['is_verified'];
                }

                Response::paginated($users, $total, $page, $perPage);

            } elseif ($requestMethod === 'PUT') {
                $data = Request::body();
                if (!$param) Response::error('ID gerekli', 400);
                
                if ((int)$param === (int)$authUser['id']) {
                    if (isset($data['is_admin']) && $data['is_admin'] == 0) Response::error('Kendi yetkinizi alamazsınız', 400);
                    if (isset($data['is_banned']) && $data['is_banned'] == 1) Response::error('Kendinizi yasaklayamazsınız', 400);
                }

                $fields = []; $params = [];
                // DÜZELTME: is_admin gelirse role güncellemesi yap
                if (isset($data['is_admin'])) { 
                    $fields[] = "role = ?"; 
                    $params[] = ((int)$data['is_admin'] === 1) ? 'admin' : 'user'; 
                }
                if (isset($data['is_banned'])) { 
                    $fields[] = "is_banned = ?"; 
                    $params[] = (int)$data['is_banned']; 
                }
                
                if (!empty($fields)) {
                    $params[] = $param;
                    $db->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
                    Response::success(null, 'Kullanıcı güncellendi');
                }
                Response::error('Değişiklik yok', 400);

            } elseif ($requestMethod === 'DELETE') {
                if (!$param) Response::error('ID gerekli', 400);
                if ((int)$param === (int)$authUser['id']) Response::error('Kendinizi silemezsiniz', 400);
                $userModel->delete($param);
                Response::success(null, 'Kullanıcı silindi');
            }
            break;

        // -------------------------------------------------
        // POSTS
        // -------------------------------------------------
        case 'posts':
            if ($requestMethod === 'GET') {
                $page = (int) Request::query('page', 1);
                $perPage = (int) Request::query('per_page', 15);
                $search = Request::query('q', null);
                $offset = ($page - 1) * $perPage;

                $sql = "SELECT 
                        p.id, p.user_id, p.title, p.content, p.images, p.tags, p.created_at, p.updated_at,
                        u.username, 
                        COALESCE(u.name, u.username, 'Bilinmeyen Kullanıcı') as user_name, 
                        u.avatar as user_avatar,
                        (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id) as comments_count,
                        (SELECT COUNT(*) FROM stars l WHERE l.post_id = p.id) as stars_count,
                        0 as reposts_count
                        FROM posts p 
                        LEFT JOIN users u ON p.user_id = u.id 
                        WHERE 1=1";
                
                $countSql = "SELECT COUNT(*) FROM posts p LEFT JOIN users u ON p.user_id = u.id WHERE 1=1";
                $params = [];

                if ($search) {
                    $cond = " AND (p.title LIKE ? OR p.content LIKE ? OR u.username LIKE ?)";
                    $sql .= $cond; $countSql .= $cond;
                    $term = "%$search%";
                    $params = [$term, $term, $term];
                }

                $sql .= " ORDER BY p.created_at DESC LIMIT $perPage OFFSET $offset";

                try {
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    $stmtCount = $db->prepare($countSql);
                    $stmtCount->execute($params);
                    $total = (int)$stmtCount->fetchColumn();

                    foreach ($posts as &$post) {
                        if (isset($post['images']) && is_string($post['images'])) {
                            $decoded = json_decode($post['images'], true);
                            $post['images'] = is_array($decoded) ? $decoded : [];
                        } else {
                            $post['images'] = [];
                        }
                        
                        if (isset($post['tags']) && is_string($post['tags'])) {
                            $decoded = json_decode($post['tags'], true);
                            $post['tags'] = is_array($decoded) ? $decoded : [];
                        } else {
                            $post['tags'] = [];
                        }
                        
                        $post['id'] = (int)$post['id'];
                        $post['user_id'] = (int)$post['user_id'];
                    }

                    Response::paginated($posts, $total, $page, $perPage);
                    
                } catch (Exception $e) {
                    Response::error("Veritabanı Hatası: " . $e->getMessage(), 500);
                }

            } elseif ($requestMethod === 'DELETE') {
                if (!$param) Response::error('ID gerekli', 400);
                try {
                    $db->prepare("DELETE FROM posts WHERE id = ?")->execute([$param]);
                    Response::success(null, 'Gönderi silindi');
                } catch (Exception $e) {
                    Response::error('Silinemedi: ' . $e->getMessage());
                }
            }
            break;

        // -------------------------------------------------
        // REPORTS
        // -------------------------------------------------
        case 'reports':
            if ($requestMethod === 'GET') {
                $page = (int) Request::query('page', 1);
                $filter = Request::query('status', 'all'); 
                $status = Request::query('status', 'pending');
                if ($filter && $filter !== 'all') $status = $filter; 

                if ($status === 'all') $status = null;

                $offset = ($page - 1) * 15;

                $sql = "SELECT r.*, 
                        u1.username as reporter_username, u1.name as reporter_name, u1.avatar as reporter_avatar,
                        u2.username as target_username, u2.name as target_name, u2.avatar as target_avatar,
                        p.content as post_content
                        FROM reports r
                        LEFT JOIN users u1 ON r.reporter_id = u1.id
                        LEFT JOIN users u2 ON r.target_id = u2.id AND r.target_type = 'user'
                        LEFT JOIN posts p ON r.target_id = p.id AND r.target_type = 'post'
                        WHERE 1=1";
                
                $params = [];
                if ($status) {
                    $sql .= " AND r.status = ?";
                    $params[] = $status;
                }

                $sql .= " ORDER BY r.created_at DESC LIMIT 15 OFFSET $offset";

                try {
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    $countSql = "SELECT COUNT(*) FROM reports r WHERE 1=1" . ($status ? " AND r.status = ?" : "");
                    $stmtCount = $db->prepare($countSql);
                    if($status) $stmtCount->execute([$status]); else $stmtCount->execute();
                    $total = (int)$stmtCount->fetchColumn();

                    Response::paginated($reports, $total, $page, 15);
                    
                } catch (Exception $e) {
                    Response::paginated([], 0, $page, 15);
                }

            } elseif ($requestMethod === 'PUT') {
                if (!$param) Response::error('ID gerekli', 400);
                $data = Request::body();
                
                $fields = []; $params = [];
                if (isset($data['status'])) { $fields[] = "status = ?"; $params[] = $data['status']; }
                if (isset($data['admin_notes'])) { $fields[] = "admin_notes = ?"; $params[] = $data['admin_notes']; }
                
                if (!empty($fields)) {
                    $params[] = $param;
                    $db->prepare("UPDATE reports SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
                    Response::success(null, 'Şikayet güncellendi');
                }
                Response::success(null, 'Değişiklik yok');
            }
            break;

        // -------------------------------------------------
        // SETTINGS
        // -------------------------------------------------
        case 'settings':
        case 'seo':
        case 'adsense':
            if ($requestMethod === 'GET') {
                $keys = [];
                $allSettings = getSystemSettings($db);
                
                $defaults = [];
                if ($endpoint === 'settings') {
                    $defaults = [
                        'site_name' => 'SanatSepet', 'site_description' => '', 'site_footer' => '', 
                        'logo_url' => '', 'favicon_url' => '', 'primary_color' => '#1DA1F2', 
                        'maintenance_mode' => false
                    ];
                } elseif ($endpoint === 'seo') {
                    $defaults = [
                        'meta_title' => '', 'meta_description' => '', 'meta_keywords' => '', 
                        'og_image' => '', 'twitter_card' => 'summary', 'google_analytics_id' => '',
                        'google_search_console' => '', 'robots_txt' => '', 'sitemap_enabled' => true
                    ];
                } elseif ($endpoint === 'adsense') {
                    $defaults = [
                        'adsense_enabled' => false, 'adsense_client_id' => '', 
                        'ad_slot_feed' => '', 'ad_slot_sidebar' => '', 'ad_slot_post' => '', 'ad_frequency' => 5
                    ];
                }
                
                $merged = array_merge($defaults, $allSettings);
                $filtered = array_intersect_key($merged, $defaults);
                
                Response::success($filtered);

            } elseif ($requestMethod === 'POST' || $requestMethod === 'PUT') {
                $data = Request::body();
                saveSystemSettings($db, $data);
                Response::success(null, 'Ayarlar kaydedildi');
            }
            break;

        default:
            Response::error('Endpoint bulunamadı: ' . $endpoint, 404);
            break;
    }

} catch (Exception $e) {
    Response::error('Sunucu Hatası: ' . $e->getMessage(), 500);
}