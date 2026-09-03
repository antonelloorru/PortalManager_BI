# Release Checklist — PortalManager v1.8.40

Policy **zero-omission**: ogni voce è verificata prima del rilascio, con evidenza.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — contiene `1.8.40` |
| `manage_projects.php` | ROOT, riscritto | OK |
| `project_gantt.php` | ROOT, modificato | OK |
| `workload_overview.php` | ROOT, modificato | OK |
| `app/ProjectModel.php` | modificato | OK |
| `app/Version.php` | modificato | OK |
| `sql/migration_v1_8_40.sql` | nuovo | n/a |
| `sql/upgrade_1_7_56_to_1_8_40.sql` | nuovo, cumulativo | n/a |
| `docs/` × 6 | nuovi | n/a |

- [x] In `app/` **solo** i file effettivamente modificati. `SqlConsole.php`, copiato
      nella sandbox per eseguire il tokenizer di QA, è stato **rimosso prima del
      packaging**.
- [x] ZIP con separatore di percorso forward-slash.
- [x] ZIP della versione precedente rimosso da `/mnt/user-data/outputs/`.
- [x] Nessuno snippet o diff: tutti i file sono completi e già patchati.

## 2. Versionamento coeso

- [x] `VERSION` = `1.8.40`
- [x] `app/Version.php` → `PM_VERSION` = `1.8.40`
- [x] `app_settings`: `app_version` / `schema_version` / `release_label` = `1.8.40`
      (verificato a valle dell'esecuzione su DB di QA)
- [x] Nessuna riscrittura di versioni già rilasciate

## 3. Quality Assurance SQL

Ambiente: MariaDB in sandbox, dump reale
`PortalManager_db_portalmanager_20260810_155817.sql` (135 MB, 127 tabelle,
778 commesse), caricato su DB freschi `pm_c1` e `pm_c2`.

| Test | Strumento | Esito |
|---|---|---|
| Migration RUN1 | tokenizer reale `sql_split_statements()` di `app/SqlConsole.php` | 6 statement, ok=6, **err=0** |
| Migration RUN2 (idempotenza) | idem | 6 statement, ok=6, **err=0** |
| Consolidato RUN1 | splitter naive `explode(';')` su `pm_c2` fresco | 252 statement, ok=252, **err=0** |
| Consolidato RUN2 (idempotenza) | idem | 252 statement, ok=252, **err=0** |
| Consolidato RUN3 | tokenizer reale | 252 statement, ok=252, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file SQL
- [x] Migrazioni idempotenti: `ADD COLUMN IF NOT EXISTS`, `ADD INDEX IF NOT EXISTS`,
      `INSERT ... ON DUPLICATE KEY UPDATE`
- [x] Conteggio statement consolidato: **249 → 252** (+3: due `ALTER TABLE`, un
      `INSERT` di versione)

## 4. QA funzionale

| Verifica | Esito |
|---|---|
| Colonne standard presenti in `cm_projects` | **29 / 29** |
| Indici `idx_proj_*` dopo migration | 12 (4 preesistenti + 8 nuovi) |
| `ProjectModel::listAll()` — query reale su dump | 1.860 righe, 37 campi/riga, nessun errore |
| Mapping riga → 29 celle | 1.860 righe, **0 righe con numero di celle diverso da 29** |
| Mappa header di `import_commesse.php` | **29 / 29 mappati, nessun header non riconosciuto** |
| Import simulato del file di riferimento | 1.082 righe, UPSERT su `project_code`, esito OK |
| Copertura dopo import | `abbr`, `commerciale`, `tipo`, `stato`, `valore`, `valore a oggi`, `consuntivato`, `margine`, `stato economico`, `data inizio`: 1.082/1.082 valorizzati |
| Export XLSX con `XlsxWriter` reale | 205.374 byte, firma `PK`, 1.083 righe × 29 colonne |
| Apertura XLSX con openpyxl | OK, foglio "Lista commesse" |
| Header esportati contro file di riferimento | **identici, confronto stringa per stringa** |

## 5. Documentazione

- [x] `docs/CHANGELOG.md` — con tabella di mappatura completa dei 29 campi
- [x] `docs/TECHNICAL_DESIGN_v1_8_40.md` — relazioni fra viste, schema ER, logiche
      di calcolo, sicurezza
- [x] `docs/DEPLOYMENT_v1_8_40.md` — installazione manuale, verifica, rollback
- [x] `docs/MANUALE_ADMIN_v1_8_40.md`
- [x] `docs/MANUALE_UTENTE_v1_8_40.md`
- [x] `docs/RELEASE_CHECKLIST_v1_8_40.md` — questo documento

## 6. Sicurezza

- [x] Tutti i nuovi filtri passano da prepared statement con placeholder
- [x] `sort` validato contro whitelist, mai interpolato da input libero
- [x] L'unica interpolazione (`IN (...)` del rollup DGB) usa interi già castati
- [x] Output HTML sempre via `h()`; `link` esterno con `rel="noopener"`
- [x] Permessi verificati separatamente per vista, export e creazione
- [x] Export: buffer clean + `zlib.output_compression=0` prima del binario

## 7. Note aperte

- Nel DB di produzione allegato le 778 commesse presenti provengono dalla sola
  sincronizzazione DGB e hanno valorizzati solo `project_code`, `name` ed
  `external_link`. Per popolare le colonne economiche occorre eseguire l'import del
  file da **Gestione Commesse → Import Commesse**. La release è stata comunque
  validata end-to-end con l'import simulato delle 1.082 righe del file di
  riferimento.
- `import_commesse.php` non è incluso nel pacchetto perché **non necessita
  modifiche**: la sua mappa header era già allineata ai 29 campi.
