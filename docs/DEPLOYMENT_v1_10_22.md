# DEPLOYMENT — v1.10.22

Pacchetto `update_v1.10.22.zip` cumulativo da 1.10.06 (include v1.10.07 → v1.10.21) + `integrations/wordpress/pm-ats-1.3.3.zip`.

1. PortalManager: Sistema › Console › Aggiornamento → `update_v1.10.22.zip` → Aggiornamento DB (o `sql/migration_v1_10_07.sql` … `sql/migration_v1_10_22.sql`).
2. WordPress: caricare `pm-ats-1.3.3.zip` (sostituisci); svuotare cache del sito e Static CSS di Divi.
3. WordPress: *Lavora con noi › Impostazioni › Aspetto › Titolo della pagina* → scegliere Mostra / Nascondi / Testo personalizzato → Salva.
4. Verifica: `P:\xampp\php\php.exe tools\verify_v1_10_22.php --db=demo_portalmanager` → `0 KO`.
