<?php
// Aggiungere queste chiavi al file .env.php (fuori dal webroot, già usato da app/Env.php).
// NON committare i valori reali. Il client_secret sta SOLO qui.
return [
    // ...chiavi esistenti (URL_SECRET, ecc.)...
    'SSO_MS_ENABLED'   => '1',
    'MS_TENANT_ID'     => '00000000-0000-0000-0000-000000000000', // Directory (tenant) ID
    'MS_CLIENT_ID'     => '11111111-1111-1111-1111-111111111111', // Application (client) ID
    'MS_CLIENT_SECRET' => 'IL_TUO_CLIENT_SECRET',
    'MS_REDIRECT_URI'  => 'https://10.100.10.30/portalmanager/auth_microsoft.php',
    'MS_ALLOWED_DOMAIN'=> 'wetechs.it', // opzionale
    'MS_REQUIRE_MFA'   => '1',          // 1 = obbligatoria: rifiuta token senza amr=mfa (anche se assente)
    'MS_ACR_VALUE'     => '',           // opzionale: id Authentication Context di Entra (es. 'c1') per FORZARE la MFA in fase di login
    'MS_AUTO_PROVISION'=> '0',          // 0 = l'utente deve già esistere in `users`
];
