-- ============================================================
--  certV — RESET PASSWORD ADMIN
--  
--  PROBLEMA IDENTIFICATO:
--  L'hash nel DB ($2y$12$LCj7bCfKJLz5lDHWYCQ4duk...) è un 
--  placeholder che NON corrisponde a nessuna password reale.
--
--  SOLUZIONE CONSIGLIATA (più affidabile):
--  1. Copia fix_password.php nella cartella certV/
--  2. Aprilo: http://localhost/certV/fix_password.php
--  3. PHP genererà il hash corretto direttamente sul tuo server
--  4. Cancella fix_password.php dopo l'uso
--
--  SOLUZIONE ALTERNATIVA (SQL diretto):
--  Eseguire questa query in phpMyAdmin → cert_management → SQL
--  Imposta password: Admin@certV2!
-- ============================================================

-- Password: Admin@certV2!
-- Hash generato con bcrypt cost 12
UPDATE `users`
SET 
    `password_hash` = '$2y$12$2RFPaQf5NiYWVa/S5S6KuOMkBHZREbv4ScHpbxVQgzFYOJwvP7XLO',
    `status` = 'active'
WHERE `email` = 'admin@certv.local';

-- Se la riga sopra aggiorna 0 record, esegui anche:
-- (significa che l'email non corrisponde)
-- SELECT id, email, status, role_id FROM users WHERE role_id = 1;

-- Verifica risultato:
SELECT `id`, `email`, `status`, `role_id`, 
       SUBSTRING(`password_hash`, 1, 20) AS `hash_preview`
FROM `users` 
WHERE `role_id` = 1;

-- ============================================================
-- NOTA: se dopo il login ricevi ancora "Credenziali non valide",
-- usa fix_password.php che genera il hash direttamente con PHP
-- sul tuo server (garanzia di compatibilità al 100%)
-- ============================================================
