# DEPLOYMENT — v1.9.85

1. Backup file + DB.
2. Estrarre lo ZIP nella root del portale:
   `Expand-Archive -Path PortalManager_v1_9_85.zip -DestinationPath P:\xampp\htdocs\demo_portalmanager -Force`
   File: `VERSION`, `app/XlsxReader.php`, `app/LinkedInApplicantImporter.php`, `recruiting_posizioni.php`, `sql/`, `docs/`.
3. Eseguire `sql/migration_v1_9_85.sql` (SQL Runner). Idempotente.
4. Verifica:
   - `SELECT id,title,linkedin_code FROM job_positions WHERE linkedin_code REGEXP '[^0-9]'` → nessuna riga attesa
     (salvo codici non numerici inseriti volutamente).
   - Recruiting → Importa candidati LinkedIn → caricare il file: la colonna «ID offerta» mostra solo cifre e
     le righe risultano «associato» per le posizioni con quel codice.
5. Posizioni senza codice: impostare «Codice Posizione LinkedIn» (si può incollare l'URL dell'annuncio).

Rollback: ripristinare i 3 file PHP. La bonifica dei codici non va annullata (forma canonica compatibile).
