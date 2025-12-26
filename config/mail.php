<?php
/**
 * Mail Configuration with PHPMailer
 * SanatSepet Forum Platform
 */

// PHPMailer - try multiple paths
$phpmailerPaths = [
    __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php',
    __DIR__ . '/../PHPMailer/PHPMailer.php',
    '/home/kri94alsofcomtr/sanatsepet.tr/PHPMailer/PHPMailer.php'
];

$loaded = false;
foreach ($phpmailerPaths as $basePath) {
    $dir = dirname($basePath);
    if (file_exists($basePath)) {
        require_once $basePath;
        require_once $dir . '/SMTP.php';
        require_once $dir . '/Exception.php';
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    throw new Exception('PHPMailer not found. Please install via composer or configure path.');
}

// Namespace kullan
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class Mailer {
    private static function getConfig() {
        return [
            'host' => getenv('SMTP_HOST') ?: ($_ENV['SMTP_HOST'] ?? 'smtp.turkticaret.net'),
            'port' => (int)(getenv('SMTP_PORT') ?: ($_ENV['SMTP_PORT'] ?? 587)),
            'username' => getenv('SMTP_USER') ?: ($_ENV['SMTP_USER'] ?? 'info@kristalsoft.com.tr'),
            'password' => getenv('SMTP_PASS') ?: ($_ENV['SMTP_PASS'] ?? 'Yenieren_2536'),
            'from_email' => getenv('MAIL_FROM') ?: ($_ENV['MAIL_FROM'] ?? 'info@kristalsoft.com.tr'),
            'from_name' => getenv('MAIL_FROM_NAME') ?: ($_ENV['MAIL_FROM_NAME'] ?? 'SanatSepet by KristalSoft'),
            'encryption' => PHPMailer::ENCRYPTION_STARTTLS
        ];
    }
    
    private static function getSiteUrl() {
        return getenv('SITE_URL') ?: ($_ENV['SITE_URL'] ?? 'https://sanatsepet.tr');
    }
    
    /**
     * Send verification email
     */
    public static function sendVerificationEmail($email, $name, $token) {
        $verifyUrl = self::getSiteUrl() . "/verify-email/" . $token;
        
        $subject = 'E-posta Adresinizi Doğrulayın - SanatSepet';
        
        $body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f8fa; margin: 0; padding: 20px; }
                .container { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
                .header { background: linear-gradient(135deg, #1da1f2, #0d8bd9); padding: 30px; text-align: center; }
                .header h1 { color: #fff; margin: 0; font-size: 28px; }
                .content { padding: 40px 30px; }
                .content h2 { color: #14171a; margin-top: 0; }
                .content p { color: #657786; line-height: 1.6; }
                .button { display: inline-block; background: #1da1f2; color: #fff; padding: 14px 32px; border-radius: 30px; text-decoration: none; font-weight: bold; margin: 20px 0; }
                .button:hover { background: #0d8bd9; }
                .footer { background: #f5f8fa; padding: 20px 30px; text-align: center; color: #657786; font-size: 14px; }
                .link { color: #1da1f2; word-break: break-all; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🎨 SanatSepet</h1>
                </div>
                <div class='content'>
                    <h2>Hoş Geldiniz, {$name}!</h2>
                    <p>SanatSepet ailesine katıldığınız için teşekkür ederiz. Hesabınızı aktifleştirmek için aşağıdaki butona tıklayın:</p>
                    <p style='text-align: center;'>
                        <a href='{$verifyUrl}' class='button'>E-postamı Doğrula</a>
                    </p>
                    <p>Eğer buton çalışmıyorsa, aşağıdaki bağlantıyı tarayıcınıza kopyalayın:</p>
                    <p class='link'>{$verifyUrl}</p>
                    <p><strong>Not:</strong> Bu bağlantı 24 saat içinde geçerliliğini yitirecektir.</p>
                </div>
                <div class='footer'>
                    <p>Bu e-postayı siz talep etmediyseniz, görmezden gelebilirsiniz.</p>
                    <p>&copy; " . date('Y') . " SanatSepet. Tüm hakları saklıdır.</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        return self::send($email, $subject, $body);
    }
    
    /**
     * Send password reset email
     */
    public static function sendPasswordResetEmail($email, $name, $token) {
        $resetUrl = self::getSiteUrl() . "/reset-password/" . $token;
        
        $subject = 'Şifre Sıfırlama Talebi - SanatSepet';
        
        $body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f8fa; margin: 0; padding: 20px; }
                .container { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
                .header { background: linear-gradient(135deg, #1da1f2, #0d8bd9); padding: 30px; text-align: center; }
                .header h1 { color: #fff; margin: 0; font-size: 28px; }
                .content { padding: 40px 30px; }
                .content h2 { color: #14171a; margin-top: 0; }
                .content p { color: #657786; line-height: 1.6; }
                .button { display: inline-block; background: #1da1f2; color: #fff; padding: 14px 32px; border-radius: 30px; text-decoration: none; font-weight: bold; margin: 20px 0; }
                .footer { background: #f5f8fa; padding: 20px 30px; text-align: center; color: #657786; font-size: 14px; }
                .link { color: #1da1f2; word-break: break-all; }
                .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 4px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🔐 Şifre Sıfırlama</h1>
                </div>
                <div class='content'>
                    <h2>Merhaba, {$name}</h2>
                    <p>Hesabınız için şifre sıfırlama talebinde bulundunuz. Şifrenizi sıfırlamak için aşağıdaki butona tıklayın:</p>
                    <p style='text-align: center;'>
                        <a href='{$resetUrl}' class='button'>Şifremi Sıfırla</a>
                    </p>
                    <p>Eğer buton çalışmıyorsa, aşağıdaki bağlantıyı tarayıcınıza kopyalayın:</p>
                    <p class='link'>{$resetUrl}</p>
                    <div class='warning'>
                        <strong>⚠️ Uyarı:</strong> Bu bağlantı 1 saat içinde geçerliliğini yitirecektir.
                    </div>
                    <p>Eğer bu talebi siz yapmadıysanız, bu e-postayı görmezden gelebilirsiniz. Hesabınız güvende.</p>
                </div>
                <div class='footer'>
                    <p>&copy; " . date('Y') . " SanatSepet. Tüm hakları saklıdır.</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        return self::send($email, $subject, $body);
    }
    
    /**
     * Core send function
     */
    private static function send($to, $subject, $body) {
        $mail = new PHPMailer(true);
        $config = self::getConfig();
        
        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host = $config['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['username'];
            $mail->Password = $config['password'];
            $mail->SMTPSecure = $config['encryption'];
            $mail->Port = $config['port'];
            $mail->CharSet = 'UTF-8';
            
            // Recipients
            $mail->setFrom($config['from_email'], $config['from_name']);
            $mail->addAddress($to);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body));
            
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Mail gönderilemedi: " . $mail->ErrorInfo);
            return false;
        }
    }
}
