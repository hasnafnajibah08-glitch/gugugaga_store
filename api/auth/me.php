<?php
// api/auth/me.php
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/auth.php';
require_method('GET');

$pdo = get_pdo();
$user = current_user($pdo);

if (!$user) {
    ok([
        'authenticated' => false,
        'user' => null,
    ]);
}

ok([
    'authenticated' => true,
    'user' => [
        'id' => (int) $user['id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'phone' => $user['phone'],
        'full_name' => $user['full_name'],
        'role_name' => $user['role_name'],
        'is_admin' => is_admin_role($user['role_name']),
    ],
]);
