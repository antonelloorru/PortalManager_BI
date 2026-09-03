<?php
/**
 * certV 4.0 — logout.php
 * Distrugge la sessione in modo sicuro e reindirizza al login.
 */
require_once __DIR__ . '/app/bootstrap.php';

if (isset($_SESSION['user_id']) && function_exists('write_log')) {
    write_log('Auth', 'info', 'Logout', (int)$_SESSION['user_id']);
}

Session::destroy();
redirect('login');
