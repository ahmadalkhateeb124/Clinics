<?php
require_once __DIR__ . '/../config/config.php';
if (isLoggedIn()) {
    log_activity('logout', 'auth', 'User logged out', 'user', $_SESSION['user_id']);
}
logout();
redirect(BP_URL . 'auth/login.php');
