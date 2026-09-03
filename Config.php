<?php
/**
 * PortalManager — Config.php
 * Riconfigurato per nuovo server: P:\xampp\htdocs\portalmanager
 * Database: portalmanager
 */

define('DB_HOST',    'localhost');
define('DB_PORT',    '3306');
define('DB_NAME',    'portalmanager');     // <-- nuovo nome DB
define('DB_USER',    'root');
define('DB_PASS',    '');                  // <-- inserire eventuale password root
define('DB_CHARSET', 'utf8mb4');

define('APP_ROOT',   __DIR__);
define('UPLOAD_DIR', APP_ROOT . '/uploads/');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]
    );
} catch (\PDOException $e) {
    http_response_code(503);
    die('<div style="font-family:sans-serif;padding:40px;background:#fee2e2;color:#991b1b;border-radius:8px;margin:40px auto;max-width:500px">
         <h2>&#9888; Errore connessione DB</h2>
         <p style="margin-top:10px">Dettaglio: <code style="background:#fff;padding:2px 6px;border-radius:3px">'
         . htmlspecialchars($e->getMessage()) . '</code></p>
         <p style="margin-top:10px">Verifica <code>Config.php</code> oppure apri <a href="diag.php" style="color:#991b1b;font-weight:700">diag.php</a> per la diagnosi guidata.</p>
         </div>');
}
