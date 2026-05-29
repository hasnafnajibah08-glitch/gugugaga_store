<?php
// api/public/products.php
// Ambil produk/top up untuk game yang dipilih.
require_once __DIR__ . '/../../config/bootstrap.php';
require_method('GET');

$pdo = get_pdo();
$gameId = (int) ($_GET['game_id'] ?? 0);
$slug = clean_string($_GET['slug'] ?? '', 150);

if ($gameId <= 0 && $slug === '') {
    fail('Game wajib dipilih.', 422);
}

if ($gameId > 0) {
    $stmt = $pdo->prepare(
        'SELECT id, name, slug, publisher, image_url, id_label, id_placeholder, requires_server, server_label
         FROM games
         WHERE id = ? AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$gameId]);
} else {
    $stmt = $pdo->prepare(
        'SELECT id, name, slug, publisher, image_url, id_label, id_placeholder, requires_server, server_label
         FROM games
         WHERE slug = ? AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$slug]);
}

$game = $stmt->fetch();

if (!$game) {
    fail('Game tidak ditemukan atau sedang nonaktif.', 404);
}

$productStmt = $pdo->prepare(
    'SELECT id, game_id, name, product_type, unit_price, icon_url, min_qty, max_qty
     FROM topup_products
     WHERE game_id = ? AND is_active = 1
     ORDER BY sort_order ASC, unit_price ASC, id ASC'
);
$productStmt->execute([(int) $game['id']]);

ok([
    'game' => $game,
    'products' => $productStmt->fetchAll(),
], 'Produk berhasil dimuat.');
