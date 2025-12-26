<?php
/**
 * Posts API Endpoints
 * SanatSepet Forum Platform
 */

// CORS (Frontend 5173 portundan geliyorsa gereklidir)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Gerekli dosyalar
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../models/Post.php';

// Opsiyonel dosyalar
if (file_exists(__DIR__ . '/../config/jwt.php')) require_once __DIR__ . '/../config/jwt.php';
if (file_exists(__DIR__ . '/../models/Comment.php')) require_once __DIR__ . '/../models/Comment.php';

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
    }
}

$post = new Post();
$comment = class_exists('Comment') ? new Comment() : null;

// =====================================================
// YENİ ROUTING MANTIĞI (/post/test-1 uyumlu)
// =====================================================
$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];
$path = parse_url($requestUri, PHP_URL_PATH);

// URL'i parçala ve boş elemanları temizle
$segments = explode('/', trim($path, '/'));
$segments = array_values(array_filter($segments));

// API yapısı: /post/{action_or_slug}/{param}/{sub_param}
// Örnek: /post/test-1 (slug)
// Örnek: /post/test-1/comments (slug/param)
// Örnek: /post/search (action)

// İlk segmentin 'post' veya 'posts' olup olmadığına bak, değilse muhtemelen API prefix'i vardır, kaydır.
// Eğer sunucu direkt bu dosyaya yönlendiriyorsa (index.php), ilk segmenti kontrol et.
$baseIndex = 0;
if (isset($segments[0]) && in_array($segments[0], ['api', 'v1'])) {
    $baseIndex = 1; // Eğer url /api/post/... ise
}

// Segmentleri değişkenlere ata
$resource = $segments[$baseIndex] ?? null; // 'post' veya 'posts'
$action   = $segments[$baseIndex + 1] ?? null; // 'test-1', 'search', 'user'
$param    = $segments[$baseIndex + 2] ?? null; // 'comments', 'star', 'ahmet'
$subParam = $segments[$baseIndex + 3] ?? null; // 'reposts' (sadece user için)

// Eğer resource 'post' veya 'posts' değilse ve script direkt çağrılmadıysa hata dönebilir veya devam edebiliriz.
// Bu örnekte direkt devam ediyoruz.

// Auth Kontrolü
$authUser = null;
$userId = null;
if (class_exists('JWT')) {
    try {
        $authUser = JWT::getAuthenticatedUser();
        if ($authUser) {
            $userId = $authUser['id'];
        }
    } catch (Exception $e) {
        // Token yoksa public devam et
    }
}

try {
    switch ($requestMethod) {
        // =====================================================
        // GET İŞLEMLERİ
        // =====================================================
        case 'GET':
            // 1. Arama (/post/search?q=...)
            if ($action === 'search') {
                $query = Request::query('q', '');
                $tag = Request::query('tag');
                $page = (int) Request::query('page', 1);
                $perPage = (int) Request::query('per_page', 10);
                
                $results = $post->search($query, $userId, $page, $perPage, $tag);
                Response::success($results);
                break;
            }
            
            // 2. Trend Etiketler (/post/trending-tags)
            if ($action === 'trending-tags') {
                $limit = (int) Request::query('limit', 10);
                $tags = $post->getTrendingTags($limit);
                Response::success($tags);
                break;
            }
            
            // 3. Kullanıcı Gönderileri (/post/user/ahmet/...)
            if ($action === 'user' && $param) {
                $username = $param;
                $userSubAction = $subParam; // URL yapısı: /post/user/{username}/{reposts}
                
                $page = (int) Request::query('page', 1);
                $perPage = (int) Request::query('per_page', 10);
                
                if ($userSubAction === 'reposts') {
                    $results = $post->getUserReposts($username, $userId, $page, $perPage);
                } elseif ($userSubAction === 'starred') {
                    $results = $post->getUserStarred($username, $userId, $page, $perPage);
                } else {
                    $results = $post->getUserPosts($username, $userId, $page, $perPage);
                }
                
                Response::paginated($results['posts'], $results['total'], $page, $perPage);
                break;
            }
            
            // 4. Tekil Post veya Yorumları (/post/test-1)
            // Eğer action var ve rezerve kelimelerden (search, user) biri değilse, slug'dır.
            if ($action && !in_array($action, ['search', 'user', 'trending-tags'])) {
                
                $postData = $post->findBySlug($action, $userId);
                
                if (!$postData) {
                    Response::error('Post bulunamadı: ' . $action, 404);
                }

                // Yorumları isteniyorsa (/post/test-1/comments)
                if ($param === 'comments') {
                    if (!$comment) Response::success([], 'Yorum sistemi aktif değil');
                    
                    $page = (int) Request::query('page', 1);
                    $perPage = (int) Request::query('per_page', 20);
                    
                    $results = $comment->getByPostId($postData['id'], $page, $perPage);
                    Response::paginated($results['comments'], $results['total'], $page, $perPage);
                    break;
                }
                
                // Sadece post detayı
                Response::success($postData);
                break;
            }
            
            // 5. Ana Akış (Feed) - Sadece /post veya /posts çağrıldıysa
            // Action yoksa (null) feed döndür
            $page = (int) Request::query('page', 1);
            $perPage = (int) Request::query('per_page', 10);
            
            $results = $post->getFeed($userId, $page, $perPage);
            Response::paginated($results['posts'], $results['total'], $page, $perPage);
            break;
        
        // =====================================================
        // POST İŞLEMLERİ
        // =====================================================
        case 'POST':
            if (!$authUser) Response::error('Oturum gerekli', 401);
            
            // URL: /post/test-1/star
            if ($param === 'star') {
                $postData = $post->findBySlug($action, $authUser['id']); // $action burada slug'dır
                if (!$postData) Response::error('Post bulunamadı', 404);
                
                $result = $post->star($postData['id'], $authUser['id']);
                $updatedPost = $post->findById($postData['id'], $authUser['id']);
                
                Response::success([
                    'stars_count' => $updatedPost['stars_count'],
                    'is_starred' => true
                ], $result ? 'Yıldızlandı' : 'Zaten yıldızlanmış');
                break;
            }
            
            // URL: /post/test-1/repost
            if ($param === 'repost') {
                $postData = $post->findBySlug($action, $authUser['id']);
                if (!$postData) Response::error('Post bulunamadı', 404);
                
                if ($postData['user_id'] == $authUser['id']) {
                    Response::error('Kendi postunuzu paylaşamazsınız', 400);
                }
                
                $result = $post->repost($postData['id'], $authUser['id']);
                $updatedPost = $post->findById($postData['id'], $authUser['id']);
                
                Response::success([
                    'reposts_count' => $updatedPost['reposts_count'],
                    'is_reposted' => true
                ], $result ? 'Paylaşıldı' : 'Zaten paylaşılmış');
                break;
            }
            
            // URL: /post/test-1/comments
            if ($param === 'comments') {
                if (!$comment) Response::error('Yorum sistemi kapalı', 500);
                
                $postData = $post->findBySlug($action, $authUser['id']);
                if (!$postData) Response::error('Post bulunamadı', 404);
                
                $data = Request::body();
                if (empty($data['content'])) Response::error('Yorum içeriği gerekli', 422);
                
                $newComment = $comment->create([
                    'post_id' => $postData['id'],
                    'user_id' => $authUser['id'],
                    'parent_id' => $data['parent_id'] ?? null,
                    'content' => $data['content']
                ]);
                
                Response::success($newComment, 'Yorum eklendi', 201);
                break;
            }
            
            // Yeni Post Oluşturma (Action yani slug yoksa -> URL: /post)
            if (!$action) {
                $data = Request::body();
                
                if (empty($data['content'])) Response::error('İçerik gerekli', 422);
                
                if (isset($data['images']) && count($data['images']) > 3) {
                    Response::error('En fazla 3 resim ekleyebilirsiniz', 422);
                }
                
                if (isset($data['tags']) && count($data['tags']) > 5) {
                    Response::error('En fazla 5 etiket ekleyebilirsiniz', 422);
                }
                
                // Etiketleri normalize et (küçük harf, İngiliz alfabesi)
                $normalizedTags = [];
                if (isset($data['tags']) && is_array($data['tags'])) {
                    $turkishMap = [
                        'ç' => 'c', 'Ç' => 'c',
                        'ğ' => 'g', 'Ğ' => 'g',
                        'ı' => 'i', 'İ' => 'i',
                        'ö' => 'o', 'Ö' => 'o',
                        'ş' => 's', 'Ş' => 's',
                        'ü' => 'u', 'Ü' => 'u'
                    ];
                    foreach ($data['tags'] as $tag) {
                        $normalized = mb_strtolower(trim($tag), 'UTF-8');
                        $normalized = strtr($normalized, $turkishMap);
                        $normalized = preg_replace('/[^a-z0-9\-_]/', '', $normalized);
                        if (!empty($normalized)) {
                            $normalizedTags[] = $normalized;
                        }
                    }
                }
                
                $newPost = $post->create([
                    'user_id' => $authUser['id'],
                    'title' => $data['title'] ?? null,
                    'content' => $data['content'],
                    'images' => $data['images'] ?? [],
                    'tags' => $normalizedTags
                ]);
                
                Response::success($newPost, 'Post oluşturuldu', 201);
                break;
            }
            
            Response::error('Endpoint bulunamadı', 404);
            break;
        
        // =====================================================
        // PUT İŞLEMLERİ
        // =====================================================
        case 'PUT':
            // URL: /post/test-1
            if (!$authUser) Response::error('Oturum gerekli', 401);
            if (!$action) Response::error('Post slug gerekli', 400);
            
            $postData = $post->findBySlug($action, $authUser['id']);
            if (!$postData) Response::error('Post bulunamadı', 404);
            
            if ($postData['user_id'] != $authUser['id']) {
                Response::error('Bu işlem için yetkiniz yok', 403);
            }
            
            $data = Request::body();
            if (empty($data['content'])) Response::error('İçerik gerekli', 422);
            
            // Etiketleri normalize et (küçük harf, İngiliz alfabesi)
            $normalizedTags = [];
            if (isset($data['tags']) && is_array($data['tags'])) {
                $turkishMap = [
                    'ç' => 'c', 'Ç' => 'c',
                    'ğ' => 'g', 'Ğ' => 'g',
                    'ı' => 'i', 'İ' => 'i',
                    'ö' => 'o', 'Ö' => 'o',
                    'ş' => 's', 'Ş' => 's',
                    'ü' => 'u', 'Ü' => 'u'
                ];
                foreach ($data['tags'] as $tag) {
                    $normalized = mb_strtolower(trim($tag), 'UTF-8');
                    $normalized = strtr($normalized, $turkishMap);
                    $normalized = preg_replace('/[^a-z0-9\-_]/', '', $normalized);
                    if (!empty($normalized)) {
                        $normalizedTags[] = $normalized;
                    }
                }
            }
            
            $post->update($postData['id'], $authUser['id'], [
                'title' => $data['title'] ?? null,
                'content' => $data['content'],
                'images' => $data['images'] ?? [],
                'tags' => $normalizedTags
            ]);
            
            $updatedPost = $post->findById($postData['id'], $authUser['id']);
            Response::success($updatedPost, 'Post güncellendi');
            break;
        
        // =====================================================
        // DELETE İŞLEMLERİ
        // =====================================================
        case 'DELETE':
            if (!$authUser) Response::error('Oturum gerekli', 401);
            if (!$action) Response::error('Post slug gerekli', 400);
            
            $postData = $post->findBySlug($action, $authUser['id']);
            if (!$postData) Response::error('Post bulunamadı', 404);
            
            // URL: /post/test-1/star
            if ($param === 'star') {
                $post->unstar($postData['id'], $authUser['id']);
                $updatedPost = $post->findById($postData['id'], $authUser['id']);
                Response::success([
                    'stars_count' => $updatedPost['stars_count'],
                    'is_starred' => false
                ], 'Yıldız kaldırıldı');
                break;
            }
            
            // URL: /post/test-1/repost
            if ($param === 'repost') {
                $post->undoRepost($postData['id'], $authUser['id']);
                $updatedPost = $post->findById($postData['id'], $authUser['id']);
                Response::success([
                    'reposts_count' => $updatedPost['reposts_count'],
                    'is_reposted' => false
                ], 'Paylaşım kaldırıldı');
                break;
            }
            
            // Post Silme
            if ($postData['user_id'] != $authUser['id'] && ($authUser['role'] ?? '') !== 'admin') {
                Response::error('Bu işlem için yetkiniz yok', 403);
            }
            
            $post->delete($postData['id'], $authUser['id'], ($authUser['role'] ?? '') === 'admin');
            Response::success(null, 'Post silindi');
            break;
        
        default:
            Response::error('Method not allowed', 405);
    }

} catch (Exception $e) {
    $code = $e->getCode();
    if (!is_int($code) || $code < 100 || $code > 599) {
        $code = 500;
    }
    Response::error($e->getMessage(), $code);
}