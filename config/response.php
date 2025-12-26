<?php

class Response {

    public static function cors() {
        $allowedOrigins = [
            'http://localhost:5173',
            'http://localhost:3000',
            'https://sanatsepet.tr'
        ];
    
        if (!empty($_SERVER['HTTP_ORIGIN']) &&
            in_array($_SERVER['HTTP_ORIGIN'], $allowedOrigins, true)
        ) {
            header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
            header('Access-Control-Allow-Credentials: true');
        }
    
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token, Authorization');
        header('Access-Control-Max-Age: 86400');
    
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }


    public static function json($data, int $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        exit;
    }

    public static function success($data = null, string $message = null, int $statusCode = 200) {
        $response = ['success' => true];

        if ($message !== null) $response['message'] = $message;
        if ($data !== null)    $response['data']    = $data;

        self::json($response, $statusCode);
    }

    public static function error(string $message, int $statusCode = 400, $errors = null) {
        $response = [
            'success' => false,
            'message' => $message
        ];

        if ($errors !== null) $response['errors'] = $errors;

        self::json($response, $statusCode);
    }

    public static function paginated(array $items, int $total, int $page, int $perPage) {
        self::json([
            'success' => true,
            'data' => $items,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
                'has_more' => ($page * $perPage) < $total
            ]
        ]);
    }
}

class Request {
    private static ?array $data = null;

    public static function body(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            return json_decode($raw, true) ?? [];
        }
    
        return $_POST;
    }

    public static function get(string $key, $default = null) {
        return self::body()[$key] ?? $default;
    }

    public static function query(string $key, $default = null) {
        return $_GET[$key] ?? $default;
    }

    public static function validate(array $rules): void
    {
        $body = self::body();
        $errors = [];
    
        foreach ($rules as $field => $ruleString) {
            $rulesArray = explode('|', $ruleString);
            $data = self::body();
            $value = $data[$field] ?? null;
    
            foreach ($rulesArray as $rule) {
    
                // required
                if ($rule === 'required') {
                    if ($value === null || $value === '') {
                        $errors[$field] = ucfirst($field) . ' alanı zorunludur';
                        break;
                    }
                }
    
                if ($value === null || $value === '') {
                    continue;
                }
    
                // email
                if ($rule === 'email') {
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $errors[$field] = 'Geçerli bir e-posta adresi giriniz';
                    }
                }
    
                // min:x
                if (preg_match('/^min:(\d+)$/', $rule, $m)) {
                    if (mb_strlen($value, 'UTF-8') < (int)$m[1]) {
                        $errors[$field] = ucfirst($field) . " en az {$m[1]} karakter olmalıdır";
                    }
                }
    
                // max:x
                if (preg_match('/^max:(\d+)$/', $rule, $m)) {
                    if (mb_strlen($value, 'UTF-8') > (int)$m[1]) {
                        $errors[$field] = ucfirst($field) . " en fazla {$m[1]} karakter olmalıdır";
                    }
                }
    
                // username
                if ($rule === 'username') {
                    if (!preg_match('/^[a-zA-Z0-9_]+$/', $value)) {
                        $errors[$field] = 'Kullanıcı adı sadece harf, rakam ve alt çizgi içerebilir';
                    }
                }
            }
        }
    
        if (!empty($errors)) {
            Response::error('Doğrulama hatası', 422, $errors);
            exit;
        }
    }
}
