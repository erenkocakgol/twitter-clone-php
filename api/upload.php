<?php
/**
 * Upload API Endpoints
 * SanatSepet Forum Platform
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/jwt.php';
require_once __DIR__ . '/../config/response.php';

// Constants
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

// Ensure upload directories exist
$uploadDirs = ['avatars', 'covers', 'posts'];
foreach ($uploadDirs as $dir) {
    $path = UPLOAD_DIR . $dir;
    if (!is_dir($path)) {
        // Klasör oluştururken izin hatası olmaması için 0775 veya 0755 önerilir
        if (!@mkdir($path, 0755, true)) {
            // Klasör oluşturulamazsa loglanabilir ama sessiz devam ediyoruz
        }
    }
}

switch ($requestMethod) {
    // =====================================================
    // POST /api/upload/avatar
    // POST /api/upload/cover
    // POST /api/upload/post
    // =====================================================
    case 'POST':
        $authUser = JWT::requireAuth();
        
        // Determine upload type
        $uploadType = $action;
        if (!in_array($uploadType, ['avatar', 'cover', 'post'])) {
            Response::error('Geçersiz yükleme tipi', 400);
        }
        
        // Check if file was uploaded
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'Dosya çok büyük (sunucu limiti)',
                UPLOAD_ERR_FORM_SIZE => 'Dosya çok büyük (form limiti)',
                UPLOAD_ERR_PARTIAL => 'Dosya kısmen yüklendi',
                UPLOAD_ERR_NO_FILE => 'Dosya yüklenmedi',
                UPLOAD_ERR_NO_TMP_DIR => 'Geçici klasör bulunamadı',
                UPLOAD_ERR_CANT_WRITE => 'Dosya yazılamadı',
            ];
            $error = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
            Response::error($errorMessages[$error] ?? 'Dosya yükleme hatası', 400);
        }
        
        $file = $_FILES['file'];
        
        // Validate file size
        if ($file['size'] > MAX_FILE_SIZE) {
            Response::error('Dosya çok büyük (maksimum 5MB)', 413);
        }
        
        // Validate file type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, ALLOWED_TYPES)) {
            Response::error('Geçersiz dosya tipi. Sadece JPEG, PNG, GIF ve WebP destekleniyor.', 415);
        }
        
        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        // Uzantı yoksa mime type'dan bul
        if (!$extension) {
            $extMap = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp'
            ];
            $extension = $extMap[$mimeType] ?? 'jpg';
        }
        
        $filename = uniqid($authUser['id'] . '_') . '.' . strtolower($extension);
        
        // Determine destination folder
        $folder = $uploadType === 'avatar' ? 'avatars' : ($uploadType === 'cover' ? 'covers' : 'posts');
        $destination = UPLOAD_DIR . $folder . '/' . $filename;
        
        // Process image (resize if needed)
        $processedPath = processImage($file['tmp_name'], $mimeType, $uploadType);
        
        // Move file (CRITICAL FIX HERE)
        $saveSuccess = false;

        if ($processedPath !== $file['tmp_name']) {
            // Eğer resim işlendiyse (yeni bir dosya oluşturulduysa), rename kullanmalıyız.
            // move_uploaded_file sadece POST ile gelen ham dosyalar için çalışır.
            $saveSuccess = rename($processedPath, $destination);
        } else {
            // Eğer resim işlenmediyse, orijinal yüklenen dosyayı taşıyoruz.
            $saveSuccess = move_uploaded_file($file['tmp_name'], $destination);
        }
        
        if (!$saveSuccess) {
            // İşlenmiş geçici dosya varsa temizle
            if ($processedPath && $processedPath !== $file['tmp_name']) {
                @unlink($processedPath);
            }
            // Sunucu permission hatası veya disk dolu olabilir
            Response::error('Dosya kaydedilemedi. Klasör izinlerini kontrol edin.', 500);
        }
        
        // Generate URL
        // Not: URL yapısının frontend ile uyumlu olduğundan emin olun (örn: domain.com/uploads/...)
        $url = '/uploads/' . $folder . '/' . $filename;
        
        Response::success([
            'url' => $url,
            'filename' => $filename,
            'size' => $file['size'],
            'type' => $mimeType
        ], 'Dosya yüklendi');
        break;
    
    // =====================================================
    // DELETE /api/upload/{type}/{filename}
    // =====================================================
    case 'DELETE':
        $authUser = JWT::requireAuth();
        
        $type = $action;
        $filename = $param;
        
        if (!in_array($type, ['avatar', 'cover', 'post'])) {
            Response::error('Geçersiz dosya tipi', 400);
        }
        
        if (!$filename) {
            Response::error('Dosya adı gerekli', 400);
        }
        
        // Security: prevent directory traversal
        $filename = basename($filename);
        
        $folder = $type === 'avatar' ? 'avatars' : ($type === 'cover' ? 'covers' : 'posts');
        $filepath = UPLOAD_DIR . $folder . '/' . $filename;
        
        // Verify ownership (filename starts with user ID)
        if (!$authUser['is_admin'] && strpos($filename, $authUser['id'] . '_') !== 0) {
            Response::error('Bu dosyayı silme yetkiniz yok', 403);
        }
        
        if (file_exists($filepath)) {
            unlink($filepath);
            Response::success(null, 'Dosya silindi');
        } else {
            Response::error('Dosya bulunamadı', 404);
        }
        break;
    
    default:
        Response::error('Method not allowed', 405);
}

/**
 * Process and resize image
 */
function processImage($sourcePath, $mimeType, $type) {
    // Max dimensions based on type
    $maxDimensions = [
        'avatar' => ['width' => 400, 'height' => 400],
        'cover' => ['width' => 1500, 'height' => 500],
        'post' => ['width' => 1200, 'height' => 1200]
    ];
    
    $maxWidth = $maxDimensions[$type]['width'];
    $maxHeight = $maxDimensions[$type]['height'];
    
    // Get image dimensions
    list($width, $height) = getimagesize($sourcePath);
    
    // Check if resize is needed
    if ($width <= $maxWidth && $height <= $maxHeight) {
        return $sourcePath; // No resize needed
    }
    
    // Calculate new dimensions
    $ratio = min($maxWidth / $width, $maxHeight / $height);
    $newWidth = (int)($width * $ratio);
    $newHeight = (int)($height * $ratio);
    
    // Create image resource
    switch ($mimeType) {
        case 'image/jpeg':
            $source = imagecreatefromjpeg($sourcePath);
            break;
        case 'image/png':
            $source = imagecreatefrompng($sourcePath);
            break;
        case 'image/gif':
            $source = imagecreatefromgif($sourcePath);
            break;
        case 'image/webp':
            $source = imagecreatefromwebp($sourcePath);
            break;
        default:
            return $sourcePath;
    }
    
    if (!$source) {
        return $sourcePath;
    }
    
    // Create resized image
    $resized = imagecreatetruecolor($newWidth, $newHeight);
    
    // Preserve transparency for PNG and GIF
    if ($mimeType === 'image/png' || $mimeType === 'image/gif') {
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefill($resized, 0, 0, $transparent);
    }
    
    // Resize
    imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    
    // Save to temp file
    $tempPath = sys_get_temp_dir() . '/' . uniqid('resize_') . '.tmp';
    
    $saveResult = false;
    switch ($mimeType) {
        case 'image/jpeg':
            $saveResult = imagejpeg($resized, $tempPath, 85);
            break;
        case 'image/png':
            $saveResult = imagepng($resized, $tempPath, 8);
            break;
        case 'image/gif':
            $saveResult = imagegif($resized, $tempPath);
            break;
        case 'image/webp':
            $saveResult = imagewebp($resized, $tempPath, 85);
            break;
    }
    
    // Clean up
    imagedestroy($source);
    imagedestroy($resized);
    
    // Eğer kaydetme başarılıysa yeni yolu döndür, yoksa eskisiyle devam et
    return $saveResult ? $tempPath : $sourcePath;
}