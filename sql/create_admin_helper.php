<?php
// sql/create_admin_helper.php
// Jalankan lewat browser/CLI hanya sekali untuk membuat akun admin awal.
// Setelah berhasil, HAPUS file ini dari server.

require_once __DIR__ . '/../config/bootstrap.php';

$pdo = get_pdo();

$username = 'admin';
$email = 'admin@gugugaga.store';
$password = 'Admin12345'; // Ganti sebelum dijalankan.
$fullName = 'Admin GUGUGAGA';

$roleStmt = $pdo->prepare('SELECT id FROM roles WHERE name = ? LIMIT 1');
$roleStmt->execute(['admin']);
$role = $roleStmt->fetch();

if (!$role) {
    exit('Role admin belum ada. Jalankan seed roles terlebih dahulu.');
}

$stmt = $pdo->prepare(
    'INSERT INTO users (role_id, username, email, full_name, password_hash)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
       role_id = VALUES(role_id),
       password_hash = VALUES(password_hash),
       is_active = 1'
);

$stmt->execute([
    (int) $role['id'],
    $username,
    $email,
    $fullName,
    password_hash($password, PASSWORD_DEFAULT),
]);

echo 'Akun admin siap. Username: ' . htmlspecialchars($username) . ' Password: ' . htmlspecialchars($password) . '. HAPUS file ini sekarang.';
