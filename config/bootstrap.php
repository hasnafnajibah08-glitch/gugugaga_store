<?php
// config/bootstrap.php
// Bootstrap utama GUGUGAGA.STORE.
// Berisi koneksi database, session, JSON response, validasi method, dan audit log.

declare(strict_types=1);

// ================================
// Konfigurasi dasar
// ================================

date_default_timezone_set('Asia/Jakarta');

// Untuk XAMPP/Laragon biasanya cukup pakai default ini.
// Kalau hosting kamu berbeda, ubah nilainya di sini atau set lewat environment variable.
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'gugugaga_store');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');

define('APP_DEBUG', filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN));

// ================================
// Header JSON untuk endpoint API
// ================================

if (PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff');

    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    if (str_contains($scriptName, '/api/')) {
        header('Content-Type: application/json; charset=utf-8');
    }
}

// ================================
// Session aman
// ================================

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secureCookie = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secureCookie,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(0, '/', '', $secureCookie, true);
    }

    session_start();
}

// ================================
// Database
// ================================

function get_pdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return $pdo;
    } catch (PDOException $e) {
        if (PHP_SAPI === 'cli') {
            throw $e;
        }

        $message = APP_DEBUG
            ? 'Koneksi database gagal: ' . $e->getMessage()
            : 'Koneksi database gagal.';

        fail($message, 500);
    }
}

// ================================
// Request helper
// ================================

function require_method(string $method): void
{
    $expected = strtoupper($method);
    $actual = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($actual === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    if ($actual !== $expected) {
        fail('Method tidak diizinkan. Gunakan ' . $expected . '.', 405);
    }
}

function request_data(): array
{
    $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    $rawBody = file_get_contents('php://input');

    if (str_contains($contentType, 'application/json')) {
        if ($rawBody === false || trim($rawBody) === '') {
            return [];
        }

        $decoded = json_decode($rawBody, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            fail('Format JSON tidak valid.', 400);
        }

        return $decoded;
    }

    if (!empty($_POST)) {
        return $_POST;
    }

    if ($rawBody !== false && trim($rawBody) !== '') {
        parse_str($rawBody, $parsed);
        return is_array($parsed) ? $parsed : [];
    }

    return [];
}

function clean_string(mixed $value, int $maxLength = 255): string
{
    $value = trim((string) $value);
    $value = strip_tags($value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
    $value = preg_replace('/\s+/', ' ', $value) ?? '';

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }

    return substr($value, 0, $maxLength);
}

function client_ip(): ?string
{
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (!$candidate) {
            continue;
        }

        $ip = trim(explode(',', $candidate)[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return null;
}

// ================================
// JSON response
// ================================

function json_response(bool $success, mixed $data, string $message, int $status = 200, mixed $errors = null): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }

    $payload = [
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ];

    if ($errors !== null) {
        $payload['errors'] = $errors;
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ok(mixed $data = null, string $message = 'OK', int $status = 200): void
{
    json_response(true, $data, $message, $status);
}

function fail(string $message = 'Terjadi kesalahan.', int $status = 400, mixed $errors = null, mixed $data = null): void
{
    json_response(false, $data, $message, $status, $errors);
}

// ================================
// Audit log
// ================================

function audit_log(
    PDO $pdo,
    ?int $actorUserId,
    string $action,
    ?string $entityType = null,
    ?int $entityId = null,
    mixed $metadata = null
): void {
    try {
        $metadataJson = null;

        if ($metadata !== null) {
            $metadataJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO audit_logs
             (actor_user_id, action, entity_type, entity_id, ip_address, user_agent, metadata)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $actorUserId,
            clean_string($action, 120),
            $entityType !== null ? clean_string($entityType, 80) : null,
            $entityId,
            client_ip(),
            clean_string($_SERVER['HTTP_USER_AGENT'] ?? '', 255),
            $metadataJson,
        ]);
    } catch (Throwable $e) {
        // Audit log tidak boleh menggagalkan proses utama.
        if (APP_DEBUG && PHP_SAPI === 'cli') {
            fwrite(STDERR, 'Audit log gagal: ' . $e->getMessage() . PHP_EOL);
        }
    }
}

// Auth helper ikut dimuat otomatis supaya file API cukup require bootstrap.php saja.
// File API lama yang masih require auth.php juga tetap aman karena memakai require_once.
require_once __DIR__ . '/auth.php';
