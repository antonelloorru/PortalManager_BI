# DEPLOYMENT — v1.10.19

Pacchetto `update_v1.10.19.zip` cumulativo da 1.10.06 (include v1.10.07 → v1.10.18) + `integrations/wordpress/pm-ats-1.3.0.zip`.

1. PortalManager: Sistema › Console › Aggiornamento → `update_v1.10.19.zip` → Aggiornamento DB. In alternativa a mano: `Expand-Archive`, poi `sql/migration_v1_10_07.sql` … `sql/migration_v1_10_19.sql`; Stop/Start Apache, Ctrl+F5.
2. WordPress: Plugin › Carica plugin → `pm-ats-1.3.0.zip` → Sostituisci. Le nuove impostazioni vengono aggiunte automaticamente (schema 3).
3. Impostazioni › Aspetto → layout «Fisarmonica con modulo a lato»; pagina Lavora con noi → `[pm_ats_jobs]`.
4. PortalManager › Sito web › **Sincronizza tutto** (rimuove dal sito le note interne già pubblicate).
5. Verifica: `P:\xampp\php\php.exe tools\verify_v1_10_19.php --db=demo_portalmanager` → `0 KO`.
