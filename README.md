# 🎨 SanatSepet Backend API

<div align="center">

![PHP](https://img.shields.io/badge/PHP-8.0+-777BB4?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.0+-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![JWT](https://img.shields.io/badge/JWT-Auth-000000?style=for-the-badge&logo=json-web-tokens)
![License](https://img.shields.io/badge/License-MIT-green?style=for-the-badge)

**SanatSepet Forum Platformu için RESTful API**

[Kurulum](#-kurulum) • [API Endpoints](#-api-endpoints) • [Authentication](#-authentication) • [Güvenlik](#-güvenlik)

</div>

---

## 📋 İçindekiler

- [Gereksinimler](#-gereksinimler)
- [Kurulum](#-kurulum)
- [Yapılandırma](#%EF%B8%8F-yapılandırma)
- [API Endpoints](#-api-endpoints)
- [Authentication](#-authentication)
- [Response Format](#-response-format)
- [Hata Kodları](#-hata-kodları)
- [Güvenlik](#-güvenlik)
- [Veritabanı Şeması](#-veritabanı-şeması)

---

## 📦 Gereksinimler

| Gereksinim | Versiyon | Açıklama |
|------------|----------|----------|
| PHP | 8.0+ | `pdo_mysql`, `json`, `mbstring` extensions |
| MySQL | 8.0+ | InnoDB engine, utf8mb4 charset |
| Apache | 2.4+ | mod_rewrite enabled |
| Composer | 2.x | PHPMailer için (opsiyonel) |

---

## 🚀 Kurulum

### 1. Dosyaları Kopyalayın

```bash
# Projeyi klonlayın veya dosyaları sunucuya yükleyin
git clone https://github.com/kullaniciadi/sanatsepet-api.git
cd sanatsepet-api
```

### 2. Veritabanını Oluşturun

```bash
# MySQL'e bağlanın
mysql -u root -p

# Veritabanını oluşturun
CREATE DATABASE sanatsepet CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

# Şemayı import edin
mysql -u root -p sanatsepet < database.sql
```

### 3. Environment Dosyasını Oluşturun

```bash
cp config/.env.example config/.env
```

### 4. Environment Değişkenlerini Ayarlayın

```env
# config/.env

# ═══════════════════════════════════════
# DATABASE
# ═══════════════════════════════════════
DB_HOST=localhost
DB_NAME=sanatsepet
DB_USER=your_db_username
DB_PASS=your_db_password

# ═══════════════════════════════════════
# JWT AUTHENTICATION
# ═══════════════════════════════════════
# En az 32 karakter, rastgele oluşturun:
# php -r "echo bin2hex(random_bytes(32));"
JWT_SECRET=your_super_secret_jwt_key_minimum_32_characters

# ═══════════════════════════════════════
# SMTP / EMAIL
# ═══════════════════════════════════════
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_USER=noreply@sanatsepet.tr
SMTP_PASS=your_smtp_password
MAIL_FROM=noreply@sanatsepet.tr
MAIL_FROM_NAME=SanatSepet

# ═══════════════════════════════════════
# SITE
# ═══════════════════════════════════════
SITE_URL=https://sanatsepet.tr
```

### 5. PHPMailer Kurulumu

```bash
# Composer ile
composer require phpmailer/phpmailer

# veya manuel olarak PHPMailer klasörünü indirin
```

### 6. Klasör İzinlerini Ayarlayın

```bash
# Upload klasörleri için yazma izni
chmod -R 755 uploads/
mkdir -p uploads/avatars uploads/covers uploads/posts
chmod -R 755 uploads/
```

### 7. Apache Yapılandırması

`.htaccess` dosyası (API kök dizininde):

```apache
RewriteEngine On

# API routing
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [QSA,L]

# CORS Headers
<IfModule mod_headers.c>
    Header set Access-Control-Allow-Origin "*"
    Header set Access-Control-Allow-Methods "GET, POST, PUT, DELETE, OPTIONS"
    Header set Access-Control-Allow-Headers "Content-Type, X-Auth-Token, Authorization"
    Header set Access-Control-Max-Age "86400"
</IfModule>

# Security Headers
<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
    Header set X-Frame-Options "SAMEORIGIN"
    Header set X-XSS-Protection "1; mode=block"
</IfModule>

# Disable directory listing
Options -Indexes

# Protect sensitive files
<FilesMatch "^\.env|\.git|composer\.(json|lock)$">
    Order allow,deny
    Deny from all
</FilesMatch>
```

---

## ⚙️ Yapılandırma

### Dosya Yapısı

```
api/
├── 📂 config/
│   ├── database.php      # Veritabanı bağlantısı
│   ├── jwt.php           # JWT işlemleri
│   ├── mail.php          # E-posta yapılandırması
│   ├── response.php      # API response helpers
│   └── .env.example      # Örnek environment dosyası
│
├── 📂 models/
│   ├── User.php          # Kullanıcı modeli
│   ├── Post.php          # Post modeli
│   ├── Comment.php       # Yorum modeli
│   ├── Follow.php        # Takip modeli
│   ├── Message.php       # Mesaj modeli
│   ├── Report.php        # Şikayet modeli
│   ├── Token.php         # Token modeli
│   └── Settings.php      # Ayarlar modeli
│
├── 📂 uploads/
│   ├── avatars/          # Profil fotoğrafları
│   ├── covers/           # Kapak fotoğrafları
│   └── posts/            # Post görselleri
│
├── auth.php              # Kimlik doğrulama endpoint'leri
├── posts.php             # Post endpoint'leri
├── users.php             # Kullanıcı endpoint'leri
├── comments.php          # Yorum endpoint'leri
├── messages.php          # Mesaj endpoint'leri
├── notifications.php     # Bildirim endpoint'leri
├── upload.php            # Dosya yükleme
├── admin.php             # Admin endpoint'leri
├── settings.php          # Ayarlar endpoint'leri
├── .htaccess             # Apache yapılandırması
└── database.sql          # Veritabanı şeması
```

### CORS Yapılandırması

`config/response.php` dosyasında izin verilen origin'leri düzenleyin:

```php
$allowedOrigins = [
    'http://localhost:5173',      // Development
    'http://localhost:3000',      // Development
    'https://sanatsepet.tr',      // Production
    'https://www.sanatsepet.tr'   // Production (www)
];
```

---

## 📚 API Endpoints

### 🔐 Authentication (`auth.php`)

| Method | Endpoint | Açıklama | Auth |
|--------|----------|----------|------|
| `POST` | `/api/auth.php?action=register` | Yeni kullanıcı kaydı | ❌ |
| `POST` | `/api/auth.php?action=login` | Kullanıcı girişi | ❌ |
| `POST` | `/api/auth.php?action=logout` | Çıkış yap | ✅ |
| `GET` | `/api/auth.php?action=me` | Mevcut kullanıcı bilgisi | ✅ |
| `POST` | `/api/auth.php?action=verify-email` | E-posta doğrula | ❌ |
| `POST` | `/api/auth.php?action=resend-verification` | Doğrulama e-postası gönder | ✅ |
| `POST` | `/api/auth.php?action=forgot-password` | Şifre sıfırlama talebi | ❌ |
| `POST` | `/api/auth.php?action=reset-password` | Şifre sıfırla | ❌ |
| `POST` | `/api/auth.php?action=change-password` | Şifre değiştir | ✅ |

<details>
<summary><b>📝 Request/Response Örnekleri</b></summary>

**Register:**
```bash
curl -X POST https://api.sanatsepet.tr/auth.php?action=register \
  -H "Content-Type: application/json" \
  -d '{
    "username": "sanatci",
    "email": "sanatci@example.com",
    "password": "Sifre123!",
    "name": "Ahmet Yılmaz"
  }'
```

Response:
```json
{
  "success": true,
  "message": "Kayıt başarılı. Lütfen e-postanızı doğrulayın.",
  "data": {
    "user": {
      "id": 1,
      "username": "sanatci",
      "email": "sanatci@example.com",
      "name": "Ahmet Yılmaz"
    },
    "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."
  }
}
```

**Login:**
```bash
curl -X POST https://api.sanatsepet.tr/auth.php?action=login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "sanatci@example.com",
    "password": "Sifre123!"
  }'
```

</details>

---

### 👥 Users (`users.php`)

| Method | Endpoint | Açıklama | Auth |
|--------|----------|----------|------|
| `GET` | `/api/users.php?username={username}` | Kullanıcı profili | ⚪ |
| `GET` | `/api/users.php?action=search&q={query}` | Kullanıcı ara | ⚪ |
| `GET` | `/api/users.php?action=suggested` | Önerilen kullanıcılar | ✅ |
| `PUT` | `/api/users.php?action=profile` | Profil güncelle | ✅ |
| `PUT` | `/api/users.php?action=username` | Kullanıcı adı değiştir | ✅ |
| `PUT` | `/api/users.php?action=email` | E-posta değiştir | ✅ |
| `DELETE` | `/api/users.php?action=account` | Hesabı sil | ✅ |
| `GET` | `/api/users.php?action=notifications` | Bildirim ayarları | ✅ |
| `PUT` | `/api/users.php?action=notifications` | Bildirim ayarları güncelle | ✅ |
| `GET` | `/api/users.php?action=privacy` | Gizlilik ayarları | ✅ |
| `PUT` | `/api/users.php?action=privacy` | Gizlilik ayarları güncelle | ✅ |
| `POST` | `/api/users.php?action=block&user_id={id}` | Kullanıcı engelle | ✅ |
| `DELETE` | `/api/users.php?action=block&user_id={id}` | Engeli kaldır | ✅ |
| `GET` | `/api/users.php?action=blocked` | Engellenen kullanıcılar | ✅ |

> ⚪ = Opsiyonel auth (giriş yapılmışsa ek bilgi döner)

<details>
<summary><b>📝 Request/Response Örnekleri</b></summary>

**Get Profile:**
```bash
curl -X GET "https://api.sanatsepet.tr/users.php?username=sanatci" \
  -H "X-Auth-Token: your-jwt-token"
```

Response:
```json
{
  "success": true,
  "data": {
    "id": 1,
    "username": "sanatci",
    "name": "Ahmet Yılmaz",
    "bio": "Dijital sanatçı 🎨",
    "avatar": "/uploads/avatars/abc123.jpg",
    "cover": "/uploads/covers/def456.jpg",
    "followers_count": 150,
    "following_count": 75,
    "posts_count": 42,
    "is_following": true,
    "is_followed_by": false,
    "created_at": "2024-01-15T10:30:00Z"
  }
}
```

**Update Profile:**
```bash
curl -X PUT "https://api.sanatsepet.tr/users.php?action=profile" \
  -H "Content-Type: application/json" \
  -H "X-Auth-Token: your-jwt-token" \
  -d '{
    "name": "Ahmet Yılmaz",
    "bio": "Dijital sanatçı ve illüstratör 🎨",
    "location": "İstanbul",
    "website": "https://ahmetyilmaz.art"
  }'
```

</details>

---

### 👣 Follows (`users.php`)

| Method | Endpoint | Açıklama | Auth |
|--------|----------|----------|------|
| `GET` | `/api/users.php?action=followers&username={username}` | Takipçiler | ⚪ |
| `GET` | `/api/users.php?action=following&username={username}` | Takip edilenler | ⚪ |
| `POST` | `/api/users.php?action=follow&user_id={id}` | Takip et | ✅ |
| `DELETE` | `/api/users.php?action=follow&user_id={id}` | Takibi bırak | ✅ |

<details>
<summary><b>📝 Request/Response Örnekleri</b></summary>

**Follow User:**
```bash
curl -X POST "https://api.sanatsepet.tr/users.php?action=follow&user_id=5" \
  -H "X-Auth-Token: your-jwt-token"
```

Response:
```json
{
  "success": true,
  "message": "Kullanıcı takip edildi"
}
```

**Get Followers:**
```bash
curl -X GET "https://api.sanatsepet.tr/users.php?action=followers&username=sanatci&page=1"
```

Response:
```json
{
  "success": true,
  "data": [
    {
      "id": 2,
      "username": "ressam",
      "name": "Ayşe Kaya",
      "avatar": "/uploads/avatars/xyz789.jpg",
      "bio": "Yağlı boya ressamı",
      "followers_count": 89,
      "is_following": false
    }
  ],
  "pagination": {
    "total": 150,
    "page": 1,
    "per_page": 20,
    "total_pages": 8,
    "has_more": true
  }
}
```

</details>

---

### 📝 Posts (`posts.php`)

| Method | Endpoint | Açıklama | Auth |
|--------|----------|----------|------|
| `GET` | `/api/posts.php` | Ana akış (feed) | ⚪ |
| `GET` | `/api/posts.php?slug={slug}` | Tek post | ⚪ |
| `GET` | `/api/posts.php?action=search&q={query}` | Post ara | ⚪ |
| `GET` | `/api/posts.php?action=search&tag={tag}` | Etikete göre ara | ⚪ |
| `GET` | `/api/posts.php?action=trending` | Trend etiketler | ❌ |
| `GET` | `/api/posts.php?action=user&username={username}` | Kullanıcı postları | ⚪ |
| `GET` | `/api/posts.php?action=reposts&username={username}` | Kullanıcı repostları | ⚪ |
| `GET` | `/api/posts.php?action=starred&username={username}` | Kullanıcı yıldızları | ⚪ |
| `POST` | `/api/posts.php` | Post oluştur | ✅ |
| `PUT` | `/api/posts.php?id={id}` | Post güncelle | ✅ |
| `DELETE` | `/api/posts.php?id={id}` | Post sil | ✅ |
| `POST` | `/api/posts.php?action=star&id={id}` | Yıldızla | ✅ |
| `DELETE` | `/api/posts.php?action=star&id={id}` | Yıldızı kaldır | ✅ |
| `POST` | `/api/posts.php?action=repost&id={id}` | Repost yap | ✅ |
| `DELETE` | `/api/posts.php?action=repost&id={id}` | Repost geri al | ✅ |

<details>
<summary><b>📝 Request/Response Örnekleri</b></summary>

**Create Post:**
```bash
curl -X POST "https://api.sanatsepet.tr/posts.php" \
  -H "Content-Type: application/json" \
  -H "X-Auth-Token: your-jwt-token" \
  -d '{
    "content": "Yeni çalışmam! 🎨 #dijitalsanat #illustration",
    "images": ["/uploads/posts/img123.jpg"],
    "tags": ["dijitalsanat", "illustration"]
  }'
```

Response:
```json
{
  "success": true,
  "message": "Post oluşturuldu",
  "data": {
    "id": 42,
    "slug": "yeni-calismam-abc123",
    "content": "Yeni çalışmam! 🎨 #dijitalsanat #illustration",
    "images": ["/uploads/posts/img123.jpg"],
    "tags": ["dijitalsanat", "illustration"],
    "user_id": 1,
    "username": "sanatci",
    "user_name": "Ahmet Yılmaz",
    "user_avatar": "/uploads/avatars/abc123.jpg",
    "stars_count": 0,
    "reposts_count": 0,
    "comments_count": 0,
    "is_starred": false,
    "is_reposted": false,
    "created_at": "2024-01-20T15:30:00Z"
  }
}
```

**Get Feed:**
```bash
curl -X GET "https://api.sanatsepet.tr/posts.php?page=1&per_page=10" \
  -H "X-Auth-Token: your-jwt-token"
```

</details>

---

### 💬 Comments (`posts.php`, `comments.php`)

| Method | Endpoint | Açıklama | Auth |
|--------|----------|----------|------|
| `GET` | `/api/posts.php?action=comments&id={post_id}` | Yorumları getir | ⚪ |
| `POST` | `/api/posts.php?action=comments&id={post_id}` | Yorum ekle | ✅ |
| `DELETE` | `/api/comments.php?id={id}` | Yorum sil | ✅ |

<details>
<summary><b>📝 Request/Response Örnekleri</b></summary>

**Add Comment:**
```bash
curl -X POST "https://api.sanatsepet.tr/posts.php?action=comments&id=42" \
  -H "Content-Type: application/json" \
  -H "X-Auth-Token: your-jwt-token" \
  -d '{
    "content": "Harika bir çalışma! 👏",
    "parent_id": null
  }'
```

Response:
```json
{
  "success": true,
  "data": {
    "id": 156,
    "post_id": 42,
    "user_id": 2,
    "username": "ressam",
    "user_name": "Ayşe Kaya",
    "user_avatar": "/uploads/avatars/xyz789.jpg",
    "content": "Harika bir çalışma! 👏",
    "parent_id": null,
    "replies": [],
    "created_at": "2024-01-20T16:00:00Z"
  }
}
```

</details>

---

### 💌 Messages (`messages.php`)

| Method | Endpoint | Açıklama | Auth |
|--------|----------|----------|------|
| `GET` | `/api/messages.php` | Konuşmalar | ✅ |
| `GET` | `/api/messages.php?conversation_id={id}` | Mesajlar | ✅ |
| `POST` | `/api/messages.php` | Mesaj gönder | ✅ |
| `PUT` | `/api/messages.php?action=read&conversation_id={id}` | Okundu işaretle | ✅ |
| `DELETE` | `/api/messages.php?id={id}` | Mesaj sil | ✅ |
| `DELETE` | `/api/messages.php?conversation_id={id}` | Konuşmayı gizle | ✅ |
| `GET` | `/api/messages.php?action=unread` | Okunmamış sayısı | ✅ |

<details>
<summary><b>📝 Request/Response Örnekleri</b></summary>

**Send Message:**
```bash
curl -X POST "https://api.sanatsepet.tr/messages.php" \
  -H "Content-Type: application/json" \
  -H "X-Auth-Token: your-jwt-token" \
  -d '{
    "recipient_id": 5,
    "content": "Merhaba, çalışmalarını çok beğendim!"
  }'
```

**Get Conversations:**
```bash
curl -X GET "https://api.sanatsepet.tr/messages.php" \
  -H "X-Auth-Token: your-jwt-token"
```

Response:
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "other_user_id": 5,
      "other_username": "ressam",
      "other_name": "Ayşe Kaya",
      "other_avatar": "/uploads/avatars/xyz789.jpg",
      "last_message": "Teşekkürler! 🙏",
      "last_message_at": "2024-01-20T17:30:00Z",
      "unread_count": 2
    }
  ],
  "pagination": {
    "total": 5,
    "page": 1,
    "per_page": 20,
    "total_pages": 1,
    "has_more": false
  }
}
```

</details>

---

### 🔔 Notifications (`notifications.php`)

| Method | Endpoint | Açıklama | Auth |
|--------|----------|----------|------|
| `GET` | `/api/notifications.php` | Bildirimleri getir | ✅ |
| `PUT` | `/api/notifications.php?action=read&id={id}` | Okundu işaretle | ✅ |
| `PUT` | `/api/notifications.php?action=read-all` | Tümünü okundu işaretle | ✅ |
| `GET` | `/api/notifications.php?action=unread` | Okunmamış sayısı | ✅ |

---

### 📤 Upload (`upload.php`)

| Method | Endpoint | Açıklama | Auth |
|--------|----------|----------|------|
| `POST` | `/api/upload.php?type=avatar` | Avatar yükle | ✅ |
| `POST` | `/api/upload.php?type=cover` | Kapak fotoğrafı yükle | ✅ |
| `POST` | `/api/upload.php?type=post` | Post görseli yükle | ✅ |
| `DELETE` | `/api/upload.php?type={type}&filename={name}` | Dosya sil | ✅ |

<details>
<summary><b>📝 Request/Response Örnekleri</b></summary>

**Upload Image:**
```bash
curl -X POST "https://api.sanatsepet.tr/upload.php?type=post" \
  -H "X-Auth-Token: your-jwt-token" \
  -F "file=@/path/to/image.jpg"
```

Response:
```json
{
  "success": true,
  "data": {
    "url": "/uploads/posts/abc123_1705764600.jpg",
    "filename": "abc123_1705764600.jpg"
  }
}
```

**Desteklenen formatlar:** JPG, JPEG, PNG, GIF, WebP  
**Maksimum boyut:** 5MB (avatar/cover), 10MB (post)

</details>

---

### 🚨 Reports (`reports.php`)

| Method | Endpoint | Açıklama | Auth |
|--------|----------|----------|------|
| `POST` | `/api/reports.php` | Şikayet oluştur | ✅ |

<details>
<summary><b>📝 Request/Response Örnekleri</b></summary>

**Create Report:**
```bash
curl -X POST "https://api.sanatsepet.tr/reports.php" \
  -H "Content-Type: application/json" \
  -H "X-Auth-Token: your-jwt-token" \
  -d '{
    "post_id": 42,
    "reason": "spam",
    "description": "Reklam içeriği paylaşıyor"
  }'
```

**Şikayet nedenleri:** `spam`, `harassment`, `hate_speech`, `violence`, `nudity`, `copyright`, `other`

</details>

---

### ⚙️ Settings (`settings.php`)

| Method | Endpoint | Açıklama | Auth |
|--------|----------|----------|------|
| `GET` | `/api/settings.php` | Genel ayarlar | ❌ |
| `GET` | `/api/settings.php?action=seo` | SEO ayarları | ❌ |
| `GET` | `/api/settings.php?action=adsense` | AdSense ayarları | ❌ |

---

### 🔧 Admin (`admin.php`)

> ⚠️ Tüm admin endpoint'leri `admin` rolü gerektirir.

| Method | Endpoint | Açıklama |
|--------|----------|----------|
| `GET` | `/api/admin.php?action=dashboard` | Dashboard istatistikleri |
| `GET` | `/api/admin.php?action=users` | Kullanıcı listesi |
| `GET` | `/api/admin.php?action=users&id={id}` | Kullanıcı detayı |
| `PUT` | `/api/admin.php?action=users&id={id}` | Kullanıcı güncelle |
| `DELETE` | `/api/admin.php?action=users&id={id}` | Kullanıcı sil |
| `PUT` | `/api/admin.php?action=ban&id={id}` | Kullanıcı banla |
| `GET` | `/api/admin.php?action=posts` | Post listesi |
| `DELETE` | `/api/admin.php?action=posts&id={id}` | Post sil |
| `GET` | `/api/admin.php?action=reports` | Şikayet listesi |
| `PUT` | `/api/admin.php?action=reports&id={id}` | Şikayet güncelle |
| `GET` | `/api/admin.php?action=settings` | Tüm ayarlar |
| `PUT` | `/api/admin.php?action=settings` | Ayarları güncelle |
| `PUT` | `/api/admin.php?action=seo` | SEO güncelle |
| `PUT` | `/api/admin.php?action=adsense` | AdSense güncelle |

---

## 🔐 Authentication

API, JWT (JSON Web Token) tabanlı kimlik doğrulama kullanır.

### Token Gönderimi

```http
X-Auth-Token: eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
```

### Token Yapısı

```json
{
  "id": 1,
  "username": "sanatci",
  "email": "sanatci@example.com",
  "name": "Ahmet Yılmaz",
  "role": "user",
  "avatar": "/uploads/avatars/abc123.jpg",
  "is_verified": true,
  "iat": 1705764600,
  "exp": 1706369400
}
```

### Token Süresi

- **Varsayılan:** 7 gün (604800 saniye)
- Süre dolduğunda yeniden giriş gerekir

---

## 📦 Response Format

### Başarılı Yanıt

```json
{
  "success": true,
  "message": "İşlem başarılı",
  "data": { ... }
}
```

### Hata Yanıtı

```json
{
  "success": false,
  "message": "Hata açıklaması",
  "errors": {
    "field_name": "Hata detayı"
  }
}
```

### Sayfalanmış Yanıt

```json
{
  "success": true,
  "data": [...],
  "pagination": {
    "total": 100,
    "page": 1,
    "per_page": 10,
    "total_pages": 10,
    "has_more": true
  }
}
```

---

## ❌ Hata Kodları

| Kod | Açıklama |
|-----|----------|
| `200` | Başarılı |
| `201` | Oluşturuldu |
| `204` | İçerik yok (silme işlemleri) |
| `400` | Geçersiz istek |
| `401` | Kimlik doğrulama gerekli |
| `403` | Yetki yok |
| `404` | Bulunamadı |
| `409` | Çakışma (duplicate) |
| `422` | Doğrulama hatası |
| `429` | Çok fazla istek (rate limit) |
| `500` | Sunucu hatası |

---

## 🔒 Güvenlik

### ✅ Uygulanan Önlemler

| Önlem | Açıklama |
|-------|----------|
| **JWT Authentication** | Stateless, güvenli token tabanlı auth |
| **Password Hashing** | bcrypt ile şifre hashleme |
| **Prepared Statements** | SQL injection koruması |
| **Input Validation** | Tüm girişler doğrulanır |
| **CORS** | Kontrollü cross-origin erişim |
| **XSS Protection** | HTML encoding |
| **Rate Limiting** | Brute force koruması (önerilir) |

### 🔧 Production Kontrol Listesi

- [ ] `JWT_SECRET` environment variable olarak ayarlandı
- [ ] Varsayılan admin şifresi değiştirildi
- [ ] CORS origin'leri production URL'leri ile güncellendi
- [ ] HTTPS aktif
- [ ] Debug modları kapatıldı
- [ ] Dosya izinleri doğru ayarlandı
- [ ] `.env` dosyası .gitignore'da
- [ ] Error logging aktif, detaylı hatalar gizli
- [ ] Rate limiting aktif

---

## 🗄️ Veritabanı Şeması

### Ana Tablolar

```
users                 # Kullanıcılar
posts                 # Paylaşımlar
comments              # Yorumlar
follows               # Takip ilişkileri
stars                 # Yıldızlamalar
reposts               # Repostlar
messages              # Mesajlar
conversations         # Konuşmalar
notifications         # Bildirimler
reports               # Şikayetler
```

### Yardımcı Tablolar

```
user_settings         # Kullanıcı ayarları
user_blocks           # Engelleme listesi
email_verifications   # E-posta doğrulama tokenları
password_resets       # Şifre sıfırlama tokenları
system_settings       # Site ayarları
```

### İlişki Diyagramı

```
users ─┬─< posts ─────< comments
       │      └─< stars
       │      └─< reposts
       │
       ├─< follows (follower_id, following_id)
       │
       ├─< conversations ─< messages
       │
       ├─< notifications
       │
       └─< reports
```

---

## 👤 Varsayılan Admin Hesabı

| Alan | Değer |
|------|-------|
| Username | `admin` |
| Email | `admin@sanatsepet.com` |
| Password | `Admin123!` |

> ⚠️ **ÖNEMLİ:** Production ortamında bu bilgileri mutlaka değiştirin!

---

## 🧪 Test

### Postman Collection

API'yi test etmek için Postman collection'ı import edebilirsiniz:

```bash
# Collection dosyasını indirin
curl -O https://raw.githubusercontent.com/kullaniciadi/sanatsepet-api/main/postman_collection.json
```

### cURL ile Hızlı Test

```bash
# Health check
curl -X GET https://api.sanatsepet.tr/settings.php

# Register
curl -X POST https://api.sanatsepet.tr/auth.php?action=register \
  -H "Content-Type: application/json" \
  -d '{"username":"test","email":"test@test.com","password":"Test123!","name":"Test"}'

# Login
curl -X POST https://api.sanatsepet.tr/auth.php?action=login \
  -H "Content-Type: application/json" \
  -d '{"email":"test@test.com","password":"Test123!"}'
```

---

## 📄 Lisans

Bu proje MIT lisansı altında lisanslanmıştır.

```
MIT License

Copyright (c) 2024 SanatSepet

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

---

<div align="center">

**[⬆ Başa Dön](#-sanatsepet-backend-api)**

Made with ❤️ by [Eren Koçakgöl](https://www.erenkocakgol.com.tr)

</div>
