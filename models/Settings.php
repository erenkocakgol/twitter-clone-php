<?php
/**
 * Settings Model
 * System-wide settings (SEO, AdSense, General)
 */

require_once __DIR__ . '/../config/database.php';

class Settings {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Tüm ayarları getir
     */
    public function getAll() {
        try {
            $stmt = $this->db->query("SELECT setting_key, setting_value FROM system_settings");
            return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (PDOException $e) {
            return [];
        }
    }
    
    /**
     * Sadece genel/herkese açık ayarları getir
     */
    public function getPublic() {
        $keys = [
            'site_name', 'site_description', 'site_logo', 'favicon', 
            'contact_email', 'social_links', 'maintenance_mode'
        ];
        return $this->getByKeys($keys);
    }
    
    /**
     * SEO ayarlarını getir
     */
    public function getSEO() {
        $keys = [
            'meta_title', 'meta_description', 'site_keywords', 
            'og_image', 'twitter_card'
        ];
        return $this->getByKeys($keys);
    }
    
    /**
     * AdSense ayarlarını getir
     */
    public function getAdsense() {
        $keys = [
            'adsense_enabled', 'adsense_client_id', 
            'adsense_slot_feed', 'adsense_slot_sidebar', 'adsense_slot_post'
        ];
        return $this->getByKeys($keys);
    }
    
    /**
     * Belirli anahtarlara göre ayarları filtrele
     */
    private function getByKeys($keys) {
        $all = $this->getAll();
        $result = [];
        
        foreach ($keys as $key) {
            // Eğer veritabanında varsa al, yoksa boş string veya null dön
            $result[$key] = isset($all[$key]) ? $all[$key] : null;
        }
        
        return $result;
    }
    
    /**
     * Ayar kaydet / güncelle (Admin API kullanır)
     */
    public function set($key, $value) {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value);
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO system_settings (setting_key, setting_value) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        return $stmt->execute([$key, $value]);
    }
}