# Release Checklist — v1.8.0 (Zero-omission)
## Dati economici per anno di competenza

## Versionamento coeso
- [x] `VERSION` = 1.8.0
- [x] `app/Version.php` PM_VERSION = 1.8.0 (autoBump app/schema/release_label invariato)
- [x] `db_upgrade.php`: metadata `'1.8.0'` + `$UPGRADE_SQL['1.8.0']` registrati (righe 1169 e 2701)
- [x] `CHANGELOG.md`: voce 1.8.0 in testa
- [x] Migrazione porta `app_version`/`schema_version`/`release_label` a 1.8.0

## Integrità componenti (file completi già patchati)
- [x] Modificati: `app/CostModel.php`, `app/MenuManager.php`, `app/Version.php`,
      `employee_compensation.php`, `finance_overview.php`, `manage_permissions.php`, `db_upgrade.php`
- [x] Nuove pagine: `hr_economic_years.php`, `finance_compare.php`, `import_economics_xlsx.php`
- [x] SQL: `sql/migration_v1_8_0.sql` (migrazione) + `upgrade_1_7_56_to_1_8_0.sql` (consolidato root)
- [x] `php -l` superato su tutti i file PHP modificati/nuovi
- [x] Nessun file esistente rimosso; colonne economiche di `employees` mantenute come mirror

## Schema DB (idempotente)
- [x] `hr_economic_years` (PK year; seed 2025 is_current=1)
- [x] `hr_employee_economics` (UNIQUE employee_id+year; 16 colonne input; precisioni allineate a employees)
- [x] `hr_reference_values` + colonna `year`; UNIQUE spostata da `uq_hr_ref_key` a `uq_hr_ref_key_year`
- [x] `hr_reference_history` + colonna `year`
- [x] Backfill esercizio 2025 dai dati esistenti (solo dipendenti con almeno un campo economico)
- [x] Permessi nuove pagine per ruoli 1 (Super Admin), 2 (HR Director), 10 (Resp. Finanziario)
- [x] Pattern idempotenti: `ADD COLUMN IF NOT EXISTS`, `DROP INDEX IF EXISTS`, `ADD UNIQUE KEY IF NOT EXISTS`, `INSERT IGNORE`, UPSERT
- [x] Nessun `;` all'interno di righe di commento SQL (compat splitter naive)

## QA SQL — eseguito con l'executor reale (regola v1.7.66)
- [x] `migration_v1_8_0.sql` via tokenizer `sql_split_statements()` (SqlConsole): 16 statement,
      RUN1 ok=16 err=0, RUN2 ok=16 err=0 (idempotente) su copia del DB reale
- [x] `migration_v1_8_0.sql` via client MariaDB: RUN1/RUN2 identici; 200 righe econ 2025;
      UNIQUE swappato correttamente
- [x] `upgrade_1_7_56_to_1_8_0.sql` (consolidato root) via splitter naive `explode(';')`
      di system_update: RUN1 ok=166 err=0, RUN2 ok=166 err=0 (idempotente); app_version=1.8.0
- [x] DB reale: 288 dipendenti, 200 con dati economici migrati a 2025

## Logica applicativa — verificata su DB reale
- [x] `CostModel`: `currentYear()`=2025, `years()`, `resolveYear(2099|0)`→2025 (fallback)
- [x] `CostModel::compute`: parità tra riga `employees` e riga `hr_employee_economics` 2025
      (es. emp#2 RAL 60922,82 → TotaleFTE+CA 119949,46, FullCost 86839,39)
- [x] `finance_overview` fin_rows: JOIN anno-scoped su 200 righe; calcoli coerenti
- [x] Clonazione esercizio (hr_economic_years): 200 input + 5 riferimenti clonati; nessun duplicato
- [x] Import: template XLSX round-trip (header letti); UPSERT con decimali a virgola;
      match per Codice e per Codice fiscale; idempotenza
- [x] Confronto annualità: delta assoluto e % calcolati correttamente sui record modificati

## Pattern architetturali rispettati
- [x] PRG: tutti i POST handler prima di `header.php`, con `Csrf::verify()`
- [x] Permessi via `can()`; sezioni economiche gated dal permesso riservato HR
- [x] Anti-leak: nessun campo sensibile aggiuntivo esposto oltre a quanto già gated
- [x] Audit: `write_log` su salvataggi, import, gestione annualità
- [x] Parser file nativi: `XlsxReader`/`XlsxWriter` (no dipendenze esterne); `UploadGuard` sugli upload
- [x] Retrocompatibilità: mirror su `employees` per l'anno corrente; fallback riferimenti anno precedente

## Documentazione (obbligatoria)
- [x] `docs/TECHNICAL_DESIGN_v1.8.0.md` (schema ER, viste, logiche di calcolo, sicurezza)
- [x] `docs/MANUALE_ADMIN_v1.8.0.md`
- [x] `docs/MANUALE_UTENTE_v1.8.0.md`
- [x] `docs/DEPLOYMENT_v1.8.0.md`
- [x] `RELEASE_CHECKLIST_v1.8.0.md` (questo file)

## Packaging
- [x] ZIP versionato con file interi patchati + nuovi file + SQL + consolidato + docs + checklist
- [x] Percorsi con separatore `/` (app/, sql/, docs/)
