<?php
/**
 * PortalManager v1.9.24 — Config sample per il BFF Careers.
 * COPIARE FUORI DALLA WEBROOT (es. C:\xampp\config\careers_bff_config.php)
 * e caricare da bff.php con require.
 */
define('PM_API_BASE',      'https://hr-internal.example.com'); // FQDN interno del gestionale (via reverse proxy/mTLS)
define('PM_CLIENT_ID',     'careers_portal_prod');
define('PM_CLIENT_SECRET', getenv('PM_CLIENT_SECRET') ?: 'SOSTITUIRE_CON_SECRET_ROBUSTO_ALMENO_64_HEX');
define('PM_CA_BUNDLE',     'C:/xampp/apache/conf/ssl.crt/ca-bundle.crt');
