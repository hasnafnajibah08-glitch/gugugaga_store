<?php
// api/public/home.php
// Data homepage: games, game populer/terlaris, banner, social links, dan site settings.
require_once __DIR__ . '/../../config/bootstrap.php';
require_method('GET');

$pdo = get_pdo();

function fetch_all_safe(PDO $pdo, string $sql): array
{
    try {
        return $pdo->query($sql)->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

$settings = [];
try {
    $stmt = $pdo->query('SELECT setting_key, setting_value, setting_type FROM site_settings');
    foreach ($stmt->fetchAll() as $row) {
        $value = $row['setting_value'];
        if ($row['setting_type'] === 'boolean') {
            $value = in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
        } elseif ($row['setting_type'] === 'number') {
            $value = is_numeric($value) ? (float) $value : $value;
        } elseif ($row['setting_type'] === 'json') {
            $decoded = json_decode((string) $value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }
        $settings[$row['setting_key']] = $value;
    }
} catch (Throwable $e) {
    $settings = [];
}

$banners = fetch_all_safe($pdo,
    'SELECT id, title, subtitle, image_url, target_url
     FROM banners
     WHERE is_active = 1
     ORDER BY sort_order ASC, id ASC
     LIMIT 10'
);

$socialLinks = fetch_all_safe($pdo,
    'SELECT id, platform, url, icon_url
     FROM social_links
     WHERE is_active = 1
     ORDER BY sort_order ASC, platform ASC'
);

$salesSql = "
    SELECT ti.game_id, COALESCE(SUM(ti.quantity), 0) AS total_sold
    FROM transaction_items ti
    JOIN transactions t ON t.id = ti.transaction_id
    WHERE t.status IN ('paid', 'processing', 'success')
    GROUP BY ti.game_id
";

$games = fetch_all_safe($pdo,
    'SELECT
        g.id,
        g.name,
        g.slug,
        g.publisher,
        g.image_url,
        g.id_label,
        g.id_placeholder,
        g.requires_server,
        g.server_label,
        g.is_popular,
        g.sort_order,
        COALESCE(s.total_sold, 0) AS sales_count
     FROM games g
     LEFT JOIN (' . $salesSql . ') s ON s.game_id = g.id
     WHERE g.is_active = 1
     ORDER BY g.sort_order ASC, g.name ASC'
);

$popularGames = fetch_all_safe($pdo,
    'SELECT
        g.id,
        g.name,
        g.slug,
        g.publisher,
        g.image_url,
        g.id_label,
        g.id_placeholder,
        g.requires_server,
        g.server_label,
        g.is_popular,
        g.sort_order,
        COALESCE(s.total_sold, 0) AS sales_count
     FROM games g
     LEFT JOIN (' . $salesSql . ') s ON s.game_id = g.id
     WHERE g.is_active = 1
     ORDER BY COALESCE(s.total_sold, 0) DESC, g.is_popular DESC, g.sort_order ASC, g.name ASC
     LIMIT 8'
);

ok([
    'settings' => $settings,
    'banners' => $banners,
    'social_links' => $socialLinks,
    'games' => $games,
    'popular_games' => $popularGames,
], 'Data homepage berhasil dimuat.');
