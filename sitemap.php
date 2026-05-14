<?php
require_once __DIR__ . '/inc/conn.php';
header('Content-Type: application/xml; charset=utf-8');

$pages = [
    '' => '1.0',
    'about' => '0.8',
    'services' => '0.9',
    'therapists' => '0.7',
    'packages' => '0.8',
    'gallery' => '0.5',
    'blog' => '0.7',
    'contact' => '0.6',
    'booking' => '0.9',
];

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

foreach ($pages as $slug => $priority) {
    $url = $base_url . $slug;
    echo "  <url>\n";
    echo "    <loc>" . htmlspecialchars($url, ENT_XML1) . "</loc>\n";
    echo "    <lastmod>" . date('Y-m-d') . "</lastmod>\n";
    echo "    <priority>$priority</priority>\n";
    echo "  </url>\n";
}

if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $posts = $pdo->query("SELECT slug, updated_at FROM blog_posts WHERE status='published' AND deleted_at IS NULL ORDER BY published_at DESC LIMIT 1000")->fetchAll();
        foreach ($posts as $p) {
            echo "  <url>\n";
            echo "    <loc>" . htmlspecialchars($base_url . $p['slug'], ENT_XML1) . "</loc>\n";
            echo "    <lastmod>" . date('Y-m-d', strtotime($p['updated_at'])) . "</lastmod>\n";
            echo "    <priority>0.6</priority>\n";
            echo "  </url>\n";
        }

        $cmsPages = $pdo->query("SELECT slug, updated_at FROM pages WHERE is_published=1 AND deleted_at IS NULL")->fetchAll();
        foreach ($cmsPages as $p) {
            echo "  <url>\n";
            echo "    <loc>" . htmlspecialchars($base_url . $p['slug'], ENT_XML1) . "</loc>\n";
            echo "    <lastmod>" . date('Y-m-d', strtotime($p['updated_at'])) . "</lastmod>\n";
            echo "    <priority>0.5</priority>\n";
            echo "  </url>\n";
        }
    } catch (Throwable $e) {}
}

echo "</urlset>\n";
