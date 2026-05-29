<?php
// api/auth/register.php
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/auth.php';
require_method('POST');

$pdo = get_pdo();
$data = request_data();

$username = clean_string($data['username'] ?? '', 80);
$email = clean_string($data['email'] ?? '', 150);
$phone = clean_string($data['phone'] ?? '', 30);
$fullName = clean_string($data['full_name'] ?? '', 150);
$password = (string) ($data['password'] ?? '');

$errors = [];

if ($username === '' || !preg_match('/^[a-zA-Z0-9_.-]{3,80}$/', $username)) {
    $errors['username'] = 'Username minimal 3 karakter, hanya huruf, angka, titik, strip, dan underscore.';
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Format email tidak valid.';
}

if (strlen($password) < 6) {
    $errors['password'] = 'Password minimal 6 karakter.';
}

if ($errors) {
    fail('Validasi gagal.', 422, $errors);
}

$roleStmt = $pdo->prepare('SELECT id FROM roles WHERE name = ? LIMIT 1');
$roleStmt->execute(['customer']);
$role = $roleStmt->fetch();

if (!$role) {
    fail('Role customer belum tersedia di database.', 500);
}

try {
    $stmt = $pdo->prepare(
        'INSERT INTO users (role_id, username, email, phone, full_name, password_hash)
         VALUES (?, ?, NULLIF(?, ""), NULLIF(?, ""), NULLIF(?, ""), ?)'
    );

    $stmt->execute([
        (int) $role['id'],
        $username,
        $email,
        $phone,
        $fullName,
        password_hash($password, PASSWORD_DEFAULT),
    ]);

    $userId = (int) $pdo->lastInsertId();
    audit_log($pdo, $userId, 'register', 'users', $userId);

    ok(['user_id' => $userId], 'Registrasi berhasil.', 201);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        fail('Username atau email sudah digunakan.', 409);
    }

    fail('Gagal registrasi.', 500);
}
