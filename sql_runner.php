<?php
/**
 * sql_runner.php — confluito nella Console di sistema (v1.7.70).
 * La funzione è ora una scheda di system_console.php; questa pagina reindirizza
 * per non rompere i collegamenti esistenti (menu, bookmark, link interni).
 */
require_once('access_control.php');
require_once('functions.php');
$u_role = (int)($_SESSION['role_id'] ?? 99);
if ($u_role !== 1) { header('Location: unauthorized.php'); exit(); }
header('Location: ' . url('system_console', ['tab' => 'sql']));
exit();
