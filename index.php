<?php
ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

$requestUrl = $_GET['url'] ?? '/';
$SP         = $_GET['puid'] ?? '';

// Whitelist of static page filenames in /pages
$staticPages = ['Home','about','services','therapists','packages','blog','blog-details','contact','booking','faq','gallery','page','404'];

include_once 'inc/conn.php';

// Route
switch ($requestUrl) {
    case '/':
    case 'Home':
    case 'home':
        include 'parts/header.php';
        include 'pages/Home.php';
        include 'parts/footer.php';
        break;

    case 'sitemap.xml':
        require 'sitemap.php';
        exit;

    default:
        $pageFile = 'pages/' . basename($requestUrl) . '.php';
        if (in_array($requestUrl, $staticPages, true) && is_file($pageFile)) {
            include 'parts/header.php';
            include $pageFile;
            include 'parts/footer.php';
        } else {
            // Maybe it's a CMS slug → blog post or page
            global $pdo;
            if ($pdo instanceof PDO) {
                // Try blog post by slug
                $stmt = $pdo->prepare("SELECT id FROM blog_posts WHERE slug = ? AND status='published' AND deleted_at IS NULL LIMIT 1");
                $stmt->execute([$requestUrl]);
                if ($stmt->fetch()) {
                    $_GET['puid'] = $requestUrl;
                    include 'parts/header.php';
                    include 'pages/blog-details.php';
                    include 'parts/footer.php';
                    break;
                }
                // Try CMS page by slug
                $stmt = $pdo->prepare("SELECT id FROM pages WHERE slug = ? AND is_published=1 AND deleted_at IS NULL LIMIT 1");
                $stmt->execute([$requestUrl]);
                if ($stmt->fetch()) {
                    $_GET['puid'] = $requestUrl;
                    include 'parts/header.php';
                    include 'pages/page.php';
                    include 'parts/footer.php';
                    break;
                }
            }
            // 404
            http_response_code(404);
            include 'parts/header.php';
            include 'pages/404.php';
            include 'parts/footer.php';
        }
        break;
}
