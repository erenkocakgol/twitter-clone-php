<?php
/**
 * JWT Configuration and Helper
 * SanatSepet Forum Platform
 */

class JWT {
    private static $algorithm = 'HS256';
    private static $expiration = 604800; // 7 gün (86400 * 7)
    
    /**
     * Get secret key from environment
     */
    private static function getSecretKey() {
        $key = getenv('JWT_SECRET') ?: ($_ENV['JWT_SECRET'] ?? "your_super_secret_jwt_key_min_32_chars_msk_46_msk");
        if (!$key) {
            // Fallback for development only - MUST be set in production
            $key = 'dev_only_change_in_production_' . md5(__DIR__);
            error_log('WARNING: JWT_SECRET not set in environment. Using insecure default.');
        }
        return $key;
    }
    
    /**
     * Generate JWT token
     */
    public static function generate($payload) {
        $header = [
            'alg' => self::$algorithm,
            'typ' => 'JWT'
        ];
        
        // Payload'a zaman damgalarını ekle
        $payload['iat'] = time();
        $payload['exp'] = time() + self::$expiration;
        
        $headerEncoded = self::base64UrlEncode(json_encode($header));
        $payloadEncoded = self::base64UrlEncode(json_encode($payload));
        
        $signature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", self::getSecretKey(), true);
        $signatureEncoded = self::base64UrlEncode($signature);
        
        return "$headerEncoded.$payloadEncoded.$signatureEncoded";
    }
    
    public static function getAuthorizationHeader(): ?string
    {
        // 1. Standart $_SERVER kontrolü (PHP bunu genellikle HTTP_ ile önekiyle saklar)
        if (!empty($_SERVER['HTTP_X_AUTH_TOKEN'])) {
            return $_SERVER['HTTP_X_AUTH_TOKEN'];
        }
    
        // 2. Apache RewriteRule yönlendirmeleri için kontrol
        if (!empty($_SERVER['REDIRECT_HTTP_X_AUTH_TOKEN'])) {
            return $_SERVER['REDIRECT_HTTP_X_AUTH_TOKEN'];
        }
    
        // 3. getallheaders() ile tüm başlıkları tara
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            // Daha hızlı erişim için anahtarları küçük harfe çevirip direkt kontrol edebiliriz
            $headers = array_change_key_case($headers, CASE_LOWER);
            if (isset($headers['x-auth-token'])) {
                return $headers['x-auth-token'];
            }
        }
    
        return null;
    }



    
    /**
     * Verify and decode JWT token
     */
    public static function verify(?string $token)
    {
        if (!$token) {
            throw new Exception('Token bulunamadı');
        }
    
        // Olası "Bearer " prefix'ini temizle (ileride geri dönersen sorun olmasın)
        if (str_starts_with($token, 'Bearer ')) {
            $token = substr($token, 7);
        }
    
        $parts = explode('.', $token);
    
        if (count($parts) !== 3) {
            throw new Exception('Geçersiz token formatı');
        }
    
        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;
    
        // Header çöz
        $header = json_decode(self::base64UrlDecode($headerEncoded), true);
        if (!$header || ($header['alg'] ?? null) !== 'HS256') {
            throw new Exception('Geçersiz token algoritması');
        }
    
        // İmzayı doğrula
        $signature = self::base64UrlDecode($signatureEncoded);
        $expectedSignature = hash_hmac(
            'sha256',
            $headerEncoded . '.' . $payloadEncoded,
            self::getSecretKey(),
            true
        );
    
        if (!hash_equals($expectedSignature, $signature)) {
            throw new Exception('Geçersiz token imzası');
        }
    
        // Payload çöz
        $payload = json_decode(self::base64UrlDecode($payloadEncoded), true);
        if (!is_array($payload)) {
            throw new Exception('Geçersiz token içeriği');
        }
    
        // Use UTC time - timezone should be handled at application level
        $now = time();
        $leeway = 30; // clock skew toleransı (sn)
    
        // exp kontrolü
        if (isset($payload['exp']) && ($payload['exp'] + $leeway) < $now) {
            throw new Exception('Token süresi dolmuş');
        }
    
        // nbf kontrolü
        if (isset($payload['nbf']) && ($payload['nbf'] - $leeway) > $now) {
            throw new Exception('Token henüz aktif değil');
        }
    
        // iat kontrolü (gelecekten gelmesin)
        if (isset($payload['iat']) && ($payload['iat'] - $leeway) > $now) {
            throw new Exception('Geçersiz token zamanı');
        }
    
        return $payload;
    }

    
    /**
     * Get user from token in request header (Authorization: Bearer ...)
     */
    public static function getAuthenticatedUser()
    {
        $token = self::getAuthorizationHeader();
    
        if (!$token) {
            error_log('X-Auth-Token NOT FOUND');
            return null;
        }
    
        try {
            return self::verify(trim($token));
        } catch (\Throwable $e) {
            error_log('JWT verify error: ' . $e->getMessage());
            return null;
        }
    }




    
    /**
     * Require authentication - throws exception if not authenticated
     */
    public static function requireAuth() {
        $user = self::getAuthenticatedUser();
        if (!$user) {
            http_response_code(401);
            // JSON yanıt dönmek daha iyi olabilir
            echo json_encode(['success' => false, 'message' => 'Oturum açmanız gerekiyor']);
            exit;
        }
        
        return $user;
    }
    
    /**
     * Require admin role
     */
    public static function requireAdmin() {
        $user = self::requireAuth();
        
        // Admin kontrolü: Hem 'role' hem 'is_admin' alanlarına bakar
        $isAdmin = (isset($user['role']) && $user['role'] === 'admin') || 
                   (isset($user['is_admin']) && ($user['is_admin'] === 1 || $user['is_admin'] === true));
        
        if (!$isAdmin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok']);
            exit;
        }
        
        return $user;
    }
    
    private static function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    private static function base64UrlDecode($data) {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}