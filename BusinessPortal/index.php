<?php
require_once __DIR__ . '/config/config.php';
if (isLoggedIn()) {
    redirect(BP_URL . 'admin/');
}
redirect(BP_URL . 'auth/login.php');
