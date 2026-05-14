<?php
require_once __DIR__ . '/../config/config.php';

if (isLoggedIn()) redirect(BP_URL . 'admin/');

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email.';
    if (strlen($pass) < 6) $errors[] = 'Password is too short.';

    if (!$errors) {
        $r = attemptLogin($email, $pass);
        if ($r['ok']) {
            log_activity('login', 'auth', "User logged in: {$email}", 'user', $r['user']['id']);
            $intended = $_SESSION['_intended'] ?? (BP_URL . 'admin/');
            unset($_SESSION['_intended']);
            redirect($intended);
        }
        $errors[] = $r['msg'];
    }
    set_old(['email' => $email]);
}
?><!DOCTYPE html>
<html lang="<?= e($lang) ?>" dir="<?= e($dir) ?>">
<head>
    <meta charset="UTF-8">
    <title><?= __('login') ?> — <?= APP_NAME ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if ($dir === 'rtl'): ?>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
    <?php else: ?>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800&display=swap">
    <link rel="stylesheet" href="<?= BP_ASSETS ?>css/admin.css">
</head>
<body class="auth-body">
    <div class="auth-card shadow-lg">
        <div class="text-center mb-4">
            <div class="brand-circle"><i class="fa-solid fa-spa"></i></div>
            <h4 class="mt-3 mb-0"><?= APP_NAME_AR ?></h4>
            <p class="text-muted small"><?= __('login') ?></p>
        </div>

        <?php foreach ($errors as $err): ?>
            <div class="alert alert-danger py-2 small"><?= e($err) ?></div>
        <?php endforeach; ?>

        <form method="post" autocomplete="on">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label small"><?= __('email') ?></label>
                <input type="email" name="email" required class="form-control" value="<?= old('email') ?>" autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label small"><?= __('password') ?></label>
                <input type="password" name="password" required class="form-control" minlength="6">
            </div>
            <button type="submit" class="btn btn-teal w-100"><?= __('sign_in') ?></button>
        </form>

        <div class="text-center mt-3">
            <a href="?lang=<?= $lang === 'ar' ? 'en' : 'ar' ?>" class="small text-muted">
                <?= $lang === 'ar' ? 'English' : 'العربية' ?>
            </a>
        </div>
    </div>
    <?php clear_old(); ?>
</body>
</html>
