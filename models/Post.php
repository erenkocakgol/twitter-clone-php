<?php
/**
 * Post Model
 * SanatSepet Forum Platform
 */

require_once __DIR__ . '/../config/database.php';

class Post {
    private $db;
    private $table = 'posts';
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Helper: Post verisini işle (JSON decode vb.)
     */
    private function processPost($post) {
        if (!$post || !is_array($post)) {
            return null;
        }
    
        /* =====================
           Images (JSON → Array)
           ===================== */
        $images = [];
    
        if (isset($post['images'])) {
            $decodedImages = json_decode($post['images'], true);
    
            if (is_array($decodedImages)) {
                // null, boş string, false vb. temizle
                $images = array_values(
                    array_filter($decodedImages, fn($img) => is_string($img) && trim($img) !== '')
                );
            }
        }
    
        $post['images'] = $images;
    
        /* =====================
           Tags (JSON → Array)
           ===================== */
        $decodedTags = json_decode($post['tags'] ?? '[]', true);
        $post['tags'] = is_array($decodedTags) ? $decodedTags : [];
    
        /* =====================
           Type Casting
           ===================== */
        $post['id'] = (int) ($post['id'] ?? 0);
        $post['user_id'] = (int) ($post['user_id'] ?? 0);
        $post['stars_count'] = (int) ($post['stars_count'] ?? 0);
        $post['reposts_count'] = (int) ($post['reposts_count'] ?? 0);
        $post['comments_count'] = (int) ($post['comments_count'] ?? 0);
        $post['views_count'] = (int) ($post['views_count'] ?? 0);
    
        /* =====================
           Boolean Flags
           ===================== */
        if (array_key_exists('is_starred', $post)) {
            $post['is_starred'] = (bool) $post['is_starred'];
        }
    
        if (array_key_exists('is_reposted', $post)) {
            $post['is_reposted'] = (bool) $post['is_reposted'];
        }
    
        return $post;
    }


    /**
     * Helper: Temel SELECT sorgusu
     */
    private function getBaseQuery($userId = null) {
        $authCheck = $userId ? "
            EXISTS(
                SELECT 1 FROM stars 
                WHERE post_id = p.id AND user_id = ?
            ) AS is_starred,
            EXISTS(
                SELECT 1 FROM reposts 
                WHERE post_id = p.id AND user_id = ?
            ) AS is_reposted
        " : "0 AS is_starred, 0 AS is_reposted";
    
        return "
            SELECT 
                p.*,
                u.username,
                u.name AS user_name,
                u.avatar AS user_avatar,
    
                /* ⭐ STAR COUNT — TEK GERÇEK KAYNAK */
                (
                    SELECT COUNT(*) 
                    FROM stars 
                    WHERE post_id = p.id
                ) AS stars_count,
    
                /* 🔁 REPOST COUNT */
                (
                    SELECT COUNT(*) 
                    FROM reposts 
                    WHERE post_id = p.id
                ) AS reposts_count,
                
                /* 💬 COMMENTS COUNT — TEK GERÇEK KAYNAK */
                (
                    SELECT COUNT(*)
                    FROM comments
                    WHERE post_id = p.id
                      AND is_deleted = 0
                ) AS comments_count,
    
                $authCheck
    
            FROM posts p
            JOIN users u ON p.user_id = u.id
        ";
    }

    
    /**
     * ID ile Post bul
     */
    public function findById($id, $userId = null) {
        $sql = $this->getBaseQuery($userId) . " WHERE p.id = ? AND p.is_deleted = 0";
        $stmt = $this->db->prepare($sql);
        
        $params = $userId ? [$userId, $userId, $id] : [$id];
        $stmt->execute($params);
        
        return $this->processPost($stmt->fetch());
    }
    
    /**
     * Slug ile Post bul
     */
    public function findBySlug($slug, $userId = null) {
        $sql = $this->getBaseQuery($userId) . " WHERE p.slug = ? AND p.is_deleted = 0";
        $stmt = $this->db->prepare($sql);
        
        $params = $userId ? [$userId, $userId, $slug] : [$slug];
        $stmt->execute($params);
        $post = $stmt->fetch();
        
        if ($post) {
            // Görüntülenmeyi artır (Sadece slug ile girildiğinde)
            $this->incrementViews($post['id']);
            return $this->processPost($post);
        }
        
        return null;
    }
    
    /**
     * Yeni Post Oluştur
     */
    public function create($data) {
        $slug = $this->generateSlug($data['title'] ?? $data['content']);
        $tags = $this->normalizeTags($data['tags'] ?? []);
        
        $stmt = $this->db->prepare("
            INSERT INTO {$this->table} (user_id, slug, title, content, images, tags)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $data['user_id'],
            $slug,
            $data['title'] ?? null,
            $data['content'],
            json_encode($data['images'] ?? []),
            json_encode($tags)
        ]);
        
        return $this->findById($this->db->lastInsertId(), $data['user_id']);
    }
    
    /**
     * Post Güncelle
     */
    public function update($id, $userId, $data) {
        $tags = $this->normalizeTags($data['tags'] ?? []);
        
        $stmt = $this->db->prepare("
            UPDATE {$this->table}
            SET title = ?, content = ?, images = ?, tags = ?
            WHERE id = ? AND user_id = ?
        ");
        
        return $stmt->execute([
            $data['title'] ?? null,
            $data['content'],
            json_encode($data['images'] ?? []),
            json_encode($tags),
            $id,
            $userId
        ]);
    }
    
    /**
     * Post Sil (Soft Delete)
     */
    public function delete($id, $userId = null, $isAdmin = false) {
        if ($isAdmin) {
            $stmt = $this->db->prepare("UPDATE {$this->table} SET is_deleted = 1 WHERE id = ?");
            return $stmt->execute([$id]);
        }
        
        $stmt = $this->db->prepare("UPDATE {$this->table} SET is_deleted = 1 WHERE id = ? AND user_id = ?");
        return $stmt->execute([$id, $userId]);
    }
    
    /**
     * Ana Akış (Feed)
     */
    public function getFeed($userId = null, $page = 1, $perPage = 10) {
        $offset = ($page - 1) * $perPage;
        
        $sql = $this->getBaseQuery($userId) . " 
                WHERE p.is_deleted = 0 
                ORDER BY p.created_at DESC 
                LIMIT ? OFFSET ?";
        
        $stmt = $this->db->prepare($sql);
        
        $params = $userId ? [$userId, $userId, $perPage, $offset] : [$perPage, $offset];
        $stmt->execute($params);
        $rawPosts = $stmt->fetchAll();
        
        $posts = array_map([$this, 'processPost'], $rawPosts);
        
        // Toplam sayı
        $countStmt = $this->db->query("SELECT COUNT(*) FROM {$this->table} WHERE is_deleted = 0");
        $total = $countStmt->fetchColumn();
        
        return ['posts' => $posts, 'total' => (int)$total];
    }
    
    /**
     * Kullanıcı Postları
     */
    public function getUserPosts($username, $viewerId = null, $page = 1, $perPage = 10) {
        $offset = ($page - 1) * $perPage;
        
        $sql = $this->getBaseQuery($viewerId) . " 
                WHERE u.username = ? AND p.is_deleted = 0
                ORDER BY p.created_at DESC
                LIMIT ? OFFSET ?";
        
        $stmt = $this->db->prepare($sql);
        $params = $viewerId ? [$viewerId, $viewerId, $username, $perPage, $offset] : [$username, $perPage, $offset];
        $stmt->execute($params);
        
        $posts = array_map([$this, 'processPost'], $stmt->fetchAll());
        
        // Toplam Sayı
        $countStmt = $this->db->prepare("
            SELECT COUNT(*) FROM {$this->table} p
            JOIN users u ON p.user_id = u.id
            WHERE u.username = ? AND p.is_deleted = 0
        ");
        $countStmt->execute([$username]);
        
        return ['posts' => $posts, 'total' => (int)$countStmt->fetchColumn()];
    }

    /**
     * Yıldızla (Transaction & Counter Update)
     */
    public function star($postId, $userId) {
        try {
            $this->db->beginTransaction();
    
            $stmt = $this->db->prepare("
                INSERT IGNORE INTO stars (post_id, user_id)
                VALUES (?, ?)
            ");
            $stmt->execute([$postId, $userId]);
    
            $this->db->commit();
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }
    
    /**
     * Yıldızı Kaldır (Transaction & Counter Update)
     */
    public function unstar($postId, $userId) {
        try {
            $this->db->beginTransaction();
    
            $stmt = $this->db->prepare("
                DELETE FROM stars
                WHERE post_id = ? AND user_id = ?
                LIMIT 1
            ");
            $stmt->execute([$postId, $userId]);
    
            $this->db->commit();
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    
    /**
     * Paylaş (Repost)
     */
    public function repost($postId, $userId) {
        try {
            $this->db->beginTransaction();
    
            $stmt = $this->db->prepare("
                INSERT IGNORE INTO reposts (post_id, user_id)
                VALUES (?, ?)
            ");
            $stmt->execute([$postId, $userId]);
    
            $this->db->commit();
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    
    /**
     * Paylaşımı Geri Al
     */
    public function undorepost($postId, $userId) {
        try {
            $this->db->beginTransaction();
    
            $stmt = $this->db->prepare("
                DELETE FROM reposts
                WHERE post_id = ? AND user_id = ?
                LIMIT 1
            ");
            $stmt->execute([$postId, $userId]);
    
            $this->db->commit();
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    
    /**
     * Görüntülenme artır
     */
    private function incrementViews($postId) {
        $stmt = $this->db->prepare("UPDATE {$this->table} SET views_count = views_count + 1 WHERE id = ?");
        $stmt->execute([$postId]);
    }
    
    /**
     * Kullanıcı Repostları
     */
    public function getUserReposts($username, $viewerId = null, $page = 1, $perPage = 10) {
        $offset = ($page - 1) * $perPage;
        
        $authCheck = $viewerId ? "
            EXISTS(SELECT 1 FROM stars WHERE post_id = p.id AND user_id = ?) as is_starred,
            EXISTS(SELECT 1 FROM reposts WHERE post_id = p.id AND user_id = ?) as is_reposted
        " : "0 as is_starred, 0 as is_reposted";

        $sql = "SELECT p.*, 
                       u.username, u.name as user_name, u.avatar as user_avatar,
                       r.created_at as reposted_at,
                       ru.username as reposted_by,
                       $authCheck
                FROM reposts r
                JOIN {$this->table} p ON r.post_id = p.id
                JOIN users u ON p.user_id = u.id
                JOIN users ru ON r.user_id = ru.id
                WHERE ru.username = ? AND p.is_deleted = 0
                ORDER BY r.created_at DESC
                LIMIT ? OFFSET ?";

        $stmt = $this->db->prepare($sql);
        $params = $viewerId ? [$viewerId, $viewerId, $username, $perPage, $offset] : [$username, $perPage, $offset];
        $stmt->execute($params);
        
        $posts = array_map([$this, 'processPost'], $stmt->fetchAll());
        
        // Total Count Logic...
        $countStmt = $this->db->prepare("
            SELECT COUNT(*) FROM reposts r
            JOIN users ru ON r.user_id = ru.id
            JOIN {$this->table} p ON r.post_id = p.id
            WHERE ru.username = ? AND p.is_deleted = 0
        ");
        $countStmt->execute([$username]);
        
        return ['posts' => $posts, 'total' => (int)$countStmt->fetchColumn()];
    }

    /**
     * Kullanıcı Yıldızları
     */
    public function getUserStarred($username, $viewerId = null, $page = 1, $perPage = 10) {
        $offset = ($page - 1) * $perPage;
        
        $authCheck = $viewerId ? "
            EXISTS(SELECT 1 FROM stars WHERE post_id = p.id AND user_id = ?) as is_starred,
            EXISTS(SELECT 1 FROM reposts WHERE post_id = p.id AND user_id = ?) as is_reposted
        " : "1 as is_starred, 0 as is_reposted"; // Kendi yıldızladıkları listede olduğu için is_starred default 1 olabilir ama dinamik kontrol daha iyidir.

        $sql = "SELECT p.*, 
                       u.username, u.name as user_name, u.avatar as user_avatar,
                       s.created_at as starred_at,
                       $authCheck
                FROM stars s
                JOIN {$this->table} p ON s.post_id = p.id
                JOIN users u ON p.user_id = u.id
                JOIN users su ON s.user_id = su.id
                WHERE su.username = ? AND p.is_deleted = 0
                ORDER BY s.created_at DESC
                LIMIT ? OFFSET ?";

        $stmt = $this->db->prepare($sql);
        $params = $viewerId ? [$viewerId, $viewerId, $username, $perPage, $offset] : [$username, $perPage, $offset];
        $stmt->execute($params);
        
        $posts = array_map([$this, 'processPost'], $stmt->fetchAll());
        
        $countStmt = $this->db->prepare("
            SELECT COUNT(*) FROM stars s
            JOIN users su ON s.user_id = su.id
            JOIN {$this->table} p ON s.post_id = p.id
            WHERE su.username = ? AND p.is_deleted = 0
        ");
        $countStmt->execute([$username]);
        
        return ['posts' => $posts, 'total' => (int)$countStmt->fetchColumn()];
    }

    /**
     * Arama
     */
    public function search($query, $userId = null, $page = 1, $perPage = 10, $tag = null) {
        $offset = ($page - 1) * $perPage;
        $conditions = ["p.is_deleted = 0"];
        $params = [];
        
        // Base query parameters (userId x2)
        $baseParams = $userId ? [$userId, $userId] : [];
        
        if ($query) {
            $conditions[] = "(p.title LIKE ? OR p.content LIKE ?)";
            $params[] = "%$query%";
            $params[] = "%$query%";
        }
        
        if ($tag) {
            $conditions[] = "JSON_CONTAINS(p.tags, ?)";
            $params[] = json_encode($tag);
        }
        
        $whereClause = implode(' AND ', $conditions);
        
        // Full SQL construction
        $sql = $this->getBaseQuery($userId) . " WHERE $whereClause ORDER BY p.created_at DESC LIMIT ? OFFSET ?";
        
        // Merge parameters: Base params + Search params + Limit/Offset
        $finalParams = array_merge($baseParams, $params, [$perPage, $offset]);
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($finalParams);
        
        $posts = array_map([$this, 'processPost'], $stmt->fetchAll());
        return ['posts' => $posts];
    }

    // ... getTrendingTags, generateSlug, getStats metodları öncekiyle aynı kalabilir ...
    
    public function getTrendingTags($limit = 10) {
        $stmt = $this->db->prepare("
            SELECT JSON_UNQUOTE(tag.tag) as tag, COUNT(*) as count
            FROM {$this->table} p,
                 JSON_TABLE(p.tags, '$[*]' COLUMNS (tag JSON PATH '$')) as tag
            WHERE p.is_deleted = 0 
              AND p.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY tag.tag
            ORDER BY count DESC
            LIMIT ?
        ");
        
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    private function generateSlug($text) {
        $charMap = [
            'ç' => 'c', 'Ç' => 'c', 'ğ' => 'g', 'Ğ' => 'g',
            'ı' => 'i', 'İ' => 'i', 'ö' => 'o', 'Ö' => 'o',
            'ş' => 's', 'Ş' => 's', 'ü' => 'u', 'Ü' => 'u'
        ];
        
        $text = strtr($text, $charMap);
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');
        $text = substr($text, 0, 50);
        
        return $text . '-' . substr(uniqid(), -6);
    }
    
    /**
     * Normalize tags (lowercase, Turkish char conversion)
     */
    private function normalizeTags($tags) {
        if (!is_array($tags)) return [];
        
        $charMap = [
            'ç' => 'c', 'Ç' => 'c', 'ğ' => 'g', 'Ğ' => 'g',
            'ı' => 'i', 'İ' => 'i', 'ö' => 'o', 'Ö' => 'o',
            'ş' => 's', 'Ş' => 's', 'ü' => 'u', 'Ü' => 'u'
        ];
        
        $normalized = [];
        foreach ($tags as $tag) {
            $tag = trim($tag);
            if (empty($tag)) continue;
            
            $tag = strtr($tag, $charMap);
            $tag = strtolower($tag);
            $tag = preg_replace('/[^a-z0-9]+/', '', $tag);
            
            if (!empty($tag) && !in_array($tag, $normalized)) {
                $normalized[] = $tag;
            }
        }
        
        return $normalized;
    }
}