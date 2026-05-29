<?php
// config/auth.php
// Helper autentikasi dan role untuk GUGUGAGA.STORE.
// Cocok dengan schema roles: customer, admin, superadmin.

function ensure_session_started(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function clear_current_session(): void
{
    ensure_session_started();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            $params['secure'] ?? false,
            $params['httponly'] ?? true
        );
    }

    session_destroy();
}

function current_user(PDO $pdo): ?array
{
    ensure_session_started();

    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT u.id, u.username, u.email, u.phone, u.full_name, u.is_active, r.name AS role_name
         FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE u.id = ?
         LIMIT 1'
    );
    $stmt->execute([(int) $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || (int) $user['is_active'] !== 1) {
        clear_current_session();
        return null;
    }

    return $user;
}

function require_login_user(PDO $pdo): array
{
    $user = current_user($pdo);

    if (!$user) {
        fail('Anda harus login terlebih dahulu.', 401);
    }

    return $user;
}

function is_admin_role(?string $roleName): bool
{
    return in_array($roleName, ['admin', 'superadmin'], true);
}

function require_admin(PDO $pdo): array
{
    $user = require_login_user($pdo);

    if (!is_admin_role($user['role_name'] ?? null)) {
        fail('Akses ditolak. Halaman ini hanya untuk admin.', 403);
    }

    return $user;
}

function require_customer(PDO $pdo): array
{
    $user = require_login_user($pdo);

    if (($user['role_name'] ?? '') !== 'customer') {
        fail('Akun admin tidak diperbolehkan membuat transaksi/top up.', 403);
    }

    return $user;
}
