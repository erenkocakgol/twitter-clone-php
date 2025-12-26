<?php
/**
 * Settings API Endpoints
 * SanatSepet Forum Platform
 */



require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/jwt.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../models/Settings.php';

$settings = new Settings();

switch ($requestMethod) {
    // =====================================================
    // GET /api/settings
    // GET /api/settings/public
    // GET /api/settings/seo
    // GET /api/settings/adsense
    // =====================================================
    case 'GET':
        // Public settings (no auth required)
        if ($action === 'public' || !$action) {
            $publicSettings = $settings->getPublic();
            Response::success($publicSettings);
        }
        
        // SEO settings (for meta tags)
        if ($action === 'seo') {
            $seoSettings = $settings->getSEO();
            Response::success($seoSettings);
        }
        
        // AdSense settings (for ads)
        if ($action === 'adsense') {
            $adsenseSettings = $settings->getAdsense();
            Response::success($adsenseSettings);
        }
        
        Response::error('Geçersiz endpoint', 404);
        break;
    
    default:
        Response::error('Method not allowed', 405);
}
