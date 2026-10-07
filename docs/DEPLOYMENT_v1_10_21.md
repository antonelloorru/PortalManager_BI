# DEPLOYMENT — v1.10.21

Pacchetto `update_v1.10.21.zip` cumulativo da 1.10.06 (include v1.10.07 → v1.10.20) + `integrations/wordpress/pm-ats-1.3.2.zip`.

1. PortalManager: Sistema › Console › Aggiornamento → `update_v1.10.21.zip` → Aggiornamento DB (o `sql/migration_v1_10_07.sql` … `sql/migration_v1_10_21.sql`).
2. WordPress: caricare `pm-ats-1.3.2.zip` (sostituisci); svuotare cache del sito e Static CSS di Divi.
3. Verifica: `P:\xampp\php\php.exe tools\verify_v1_10_21.php --db=demo_portalmanager` → `0 KO`.
