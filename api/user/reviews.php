<?php
// api/user/reviews.php
// GET: daftar review publik. POST: customer kirim review.
require_once __DIR__ . '/../../config/bootstrap.php';

$pdo = get_pdo();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    $stmt = $pdo->query(
        'SELECT r.id, r.rating, r.review_text, r.created_at, COALESCE(u.username, "Pelanggan") AS username
         FROM reviews r
         LEFT JOIN users u ON u.id = r.user_id
         WHERE r.is_visible = 1
         ORDER BY r.created_at DESC
         LIMIT 20'
    );

    ok([
        'reviews' => $stmt->fetchAll(),
    ], 'Review berhasil dimuat.');
}

if ($method === 'POST') {
    $user = require_customer($pdo);
    $data = request_data();

    $rating = (int) ($data['rating'] ?? 0);
    $reviewText = clean_string($data['review_text'] ?? '', 2000);
    $transactionId = isset($data['transaction_id']) && $data['transaction_id'] !== ''
        ? (int) $data['transaction_id']
        : null;

    if ($rating < 1 || $rating > 5) {
        fail('Rating harus antara 1 sampai 5.', 422);
    }

    if ($reviewText === '') {
        fail('Ulasan wajib diisi.', 422);
    }

    if ($transactionId !== null) {
        $checkStmt = $pdo->prepare('SELECT id FROM transactions WHERE id = ? AND user_id = ? LIMIT 1');
        $checkStmt->execute([$transactionId, (int) $user['id']]);
        if (!$checkStmt->fetch()) {
            fail('Transaksi untuk ulasan tidak valid.', 422);
        }
    }

    $stmt = $pdo->prepare(
        'INSERT INTO reviews (user_id, transaction_id, rating, review_text, is_visible)
         VALUES (?, ?, ?, ?, 1)'
    );
    $stmt->execute([
        (int) $user['id'],
        $transactionId,
        $rating,
        $reviewText,
    ]);

    audit_log($pdo, (int) $user['id'], 'create_review', 'reviews', (int) $pdo->lastInsertId());

    ok(null, 'Ulasan berhasil dikirim.', 201);
}

fail('Method tidak diizinkan.', 405);
