<?php
// api/auth/login.php
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/auth.php';
require_method('POST');

ensure_session_started();

$pdo = get_pdo();
$data = request_data();

$login = clean_string($data['login'] ?? ($data['username'] ?? ''), 150);
$password = (string) ($data['password'] ?? '');

if ($login === '' || $password === '') {
    fail('Username/email dan password wajib diisi.', 422);
}

$stmt = $pdo->prepare(
    'SELECT u.id, u.username, u.email, u.password_hash, u.is_active, r.name AS role_name
     FROM users u
     JOIN roles r ON r.id = u.role_id
     WHERE u.username = ? OR u.email = ?
     LIMIT 1'
);
$stmt->execute([$login, $login]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    fail('Login gagal. Periksa username/email dan password.', 401);
}

if ((int) $user['is_active'] !== 1) {
    fail('Akun Anda sedang nonaktif.', 403);
}

session_regenerate_id(true);

$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['role_name'] = $user['role_name'];

$pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')
    ->execute([(int) $user['id']]);

audit_log($pdo, (int) $user['id'], 'login', 'users', (int) $user['id']);

$redirectUrl = is_admin_role($user['role_name']) ? 'admin.html' : 'index.html';

ok([
    'user' => [
        'id' => (int) $user['id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'role_name' => $user['role_name'],
        'is_admin' => is_admin_role($user['role_name']),
    ],
    'redirect_url' => $redirectUrl,
], 'Login berhasil.');
