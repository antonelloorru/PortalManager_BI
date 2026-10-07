# DEPLOYMENT — v1.10.20

Pacchetto `update_v1.10.20.zip` cumulativo da 1.10.06 (include v1.10.07 → v1.10.19) + `integrations/wordpress/pm-ats-1.3.1.zip`.

1. PortalManager: Sistema › Console › Aggiornamento → `update_v1.10.20.zip` → Aggiornamento DB. In alternativa `sql/migration_v1_10_07.sql` … `sql/migration_v1_10_20.sql`.
2. WordPress: caricare `pm-ats-1.3.1.zip` (sostituisci); svuotare la cache del sito.
3. Pagina «Lavora con noi»: riga a una colonna con `[pm_ats_lavora_con_noi hero="0"]` al posto di accordion e Contact Form 7 (vedi Manuale amministratore).
4. Verifica: `P:\xampp\php\php.exe tools\verify_v1_10_20.php --db=demo_portalmanager` → `0 KO`.
