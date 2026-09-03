# Release Checklist — PortalManager v1.8.42

Policy **zero-omission**: ogni voce verificata con evidenza.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — contiene `1.8.42` |
| `timesheet.php` | ROOT, modificato | OK |
| `project_gantt.php` | ROOT, modificato (da v1.8.40) | OK |
| `professionals.php` | ROOT, modificato | OK |
| `export_employees.php` | ROOT, modificato | OK |
| `project_dashboard.php` | ROOT, modificato | OK |
| `recruiting_posizioni.php` | ROOT, modificato | OK |
| `app/Version.php` | modificato | OK |
| `sql/migration_v1_8_42.sql` | nuovo | n/a |
| `sql/upgrade_1_7_56_to_1_8_42.sql` | nuovo, cumulativo | n/a |
| `docs/` × 6 | nuovi | n/a |

- [x] In `app/` solo i file effettivamente modificati
- [x] `project_gantt.php` parte dalla v1.8.40 e ne conserva l'indicatore di record:
      nessuna regressione da sovrascrittura
- [x] ZIP con separatore forward-slash
- [x] ZIP della versione precedente rimosso da `/mnt/user-data/outputs/`
- [x] File completi e già patchati, nessuno snippet o diff

## 2. Versionamento coeso

- [x] `VERSION` = `1.8.42`
- [x] `app/Version.php` → `PM_VERSION` = `1.8.42`
- [x] `app_settings` = `1.8.42`, verificato a valle dell'esecuzione su `pm_t6`
- [x] Nessuna riscrittura di versioni già rilasciate

## 3. Analisi del difetto

- [x] Causa individuata: form GET privi del campo `r`; al submit il browser
      ricostruisce la query string e lo slug del router va perso
- [x] Verificato che `timesheet` è in `Router::PAGES`, quindi anonimizzato
- [x] Ricerca **esaustiva** su tutte le 90 pagine di `Router::PAGES`, non solo
      sulla pagina segnalata
- [x] Il criterio di scansione accetta anche un `<input name="r">` scritto a mano,
      per non produrre falsi positivi

## 4. QA funzionale

| Verifica | Prima | Dopo |
|---|---|---|
| Form GET privi del campo di rotta (90 pagine) | **6** | **0** |
| `route_slug_field()` emette il campo | — | 6 / 6 |
| Ciclo `pagina → slug → pagina` | — | **6 / 6 OK** |

Slug verificati con risoluzione inversa corretta: `timesheet`, `project_gantt`,
`professionals`, `export_employees`, `project_dashboard`, `recruiting_posizioni`.

## 5. Quality Assurance SQL

DB di test creati da zero dal dump reale `portalmanager_db.sql`.

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_t5` | 4 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_t5` | 4 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_t5` | 4 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_t6` fresco | 314 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_t6` | 314 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_t6` | 314 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file SQL
- [x] Idempotenza via `ON DUPLICATE KEY UPDATE`
- [x] Conteggio statement consolidato: **313 → 314**

## 6. Sicurezza

- [x] Nessuna nuova esposizione: lo slug è già presente nell'URL della pagina che
      ospita il form
- [x] Valore HTML-escaped in uscita da `route_slug_field()`
- [x] L'anonimizzazione resta una misura di riduzione della superficie informativa,
      non un controllo di accesso: i permessi sono verificati con `can()` a
      prescindere dalla rotta
- [x] Nessuna modifica a schema, dati o logiche di autorizzazione

## 7. Documentazione

- [x] `docs/CHANGELOG.md`
- [x] `docs/TECHNICAL_DESIGN_v1_8_42.md`
- [x] `docs/DEPLOYMENT_v1_8_42.md`
- [x] `docs/MANUALE_ADMIN_v1_8_42.md`
- [x] `docs/MANUALE_UTENTE_v1_8_42.md`
- [x] `docs/RELEASE_CHECKLIST_v1_8_42.md`

## 8. Prevenzione

- [x] Voce aggiunta alla checklist: ogni nuova pagina di menu con filtri in GET
      deve includere `route_slug_field()` subito dopo il tag di apertura del form
