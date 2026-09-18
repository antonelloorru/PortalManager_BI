# Release Notes — PortalManager v1.9.47

Menu: Recruiting & Agenzie → **Posizioni aperte** (`recruiting_posizioni.php`)
Allineamento versioni: Software 1.9.47 · Schema 1.9.47 · Upgrade 1.9.47

## Nuovo campo: Codice Posizione LinkedIn
Integrato il campo **Codice Posizione LinkedIn** (ID annuncio LinkedIn) nella gestione
delle posizioni aperte.

- **DB**: nuova colonna `job_positions.linkedin_code` VARCHAR(100) NULL. La pagina la
  auto-crea al primo caricamento (auto-migration già presente); la migration SQL la
  aggiunge in modo idempotente (`ADD COLUMN IF NOT EXISTS`).
- **Form posizione** (crea/modifica): nuovo input "Codice Posizione LinkedIn" nella
  sezione Informazioni base, accanto a Data target.
- **Salvataggio**: `linkedin_code` incluso in INSERT e UPDATE di `job_positions`
  (arità verificata: 26 campi coerenti fra `$data`, INSERT e UPDATE).
- **Modifica**: il valore viene ripopolato nel form (mappa JS `fields` +
  `id="p_linkedin_code"`); `SELECT jp.*` lo porta già nel JSON della card.
- **Elenco**: il codice, se presente, è mostrato nella card della posizione.

## File
```
VERSION                          1.9.47
recruiting_posizioni.php         campo Codice Posizione LinkedIn (DB auto + form + save + lista)
sql/migration_v1_9_47.sql        ADD COLUMN IF NOT EXISTS linkedin_code + allineamento versione
docs/                            changelog, deployment
```

## QA
- `php -l recruiting_posizioni.php` OK.
- Arità INSERT/UPDATE/$data = 26 (coerente).
- ALTER/INSERT/UPDATE reali su MariaDB 10.x: colonna popolata e riletta correttamente.
- Migration RUN1/RUN2 err=0; `;` nei commenti = 0; schema_version → 1.9.47.
