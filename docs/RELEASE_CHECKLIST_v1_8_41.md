# Release Checklist — PortalManager v1.8.41

Policy **zero-omission**: ogni voce verificata con evidenza prima del rilascio.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — contiene `1.8.41` |
| `import_commesse.php` | ROOT, modificato | OK |
| `app/Version.php` | modificato | OK |
| `sql/migration_v1_8_41.sql` | nuovo, 695 KB | n/a |
| `sql/upgrade_1_7_56_to_1_8_41.sql` | nuovo, cumulativo | n/a |
| `docs/` × 6 | nuovi | n/a |

- [x] In `app/` solo i file effettivamente modificati; `SqlConsole.php`, usato in
      sandbox per il tokenizer di QA, tenuto fuori dal pacchetto.
- [x] ZIP con separatore forward-slash.
- [x] ZIP della versione precedente rimosso da `/mnt/user-data/outputs/`.
- [x] File completi e già patchati, nessuno snippet o diff.

## 2. Versionamento coeso

- [x] `VERSION` = `1.8.41`
- [x] `app/Version.php` → `PM_VERSION` = `1.8.41`
- [x] `app_settings` = `1.8.41` su tutte e tre le chiavi, verificato a valle
      dell'esecuzione su DB di QA (`pm_t3` e `pm_t4`)
- [x] Nessuna riscrittura di versioni già rilasciate

## 3. Diagnosi documentata

- [x] `diff -rq` fra i due backup: codice applicativo identico, unica differenza
      sostanziale `Config.php` (credenziali)
- [x] md5 verificati su 9 file del modulo commesse: tutti identici
- [x] Divergenza circoscritta ai dati, con tabella comparativa in CHANGELOG
- [x] Causa strutturale individuata in `import_commesse.php` e corretta

## 4. Quality Assurance SQL

Ambiente: MariaDB in sandbox; DB di test creati da zero dal dump reale
`portalmanager_db.sql` (135 MB, 127 tabelle, 778 commesse segnaposto).

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale `sql_split_statements()` | `pm_t4` | 63 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_t4` | 63 stmt, **err=0** |
| Migration RUN3 | splitter naive `explode(';')` | `pm_t4` | 63 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_t3` fresco | 313 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_t3` | 313 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_t3` | 313 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file SQL
- [x] Nessun `;` dentro le stringhe letterali: gli 8 valori che lo contenevano sono
      emessi come `CONCAT(..., CHAR(59), ...)`, così il file regge anche lo
      splitter naive senza alterare il dato memorizzato
- [x] Idempotenza garantita da `ON DUPLICATE KEY UPDATE`, `INSERT IGNORE`,
      `DROP TABLE IF EXISTS` sulla tabella ponte
- [x] Conteggio statement consolidato: **252 → 313**

## 5. QA funzionale — allineamento dei dati

Confronto fra il DB corretto (`pm_t3`, ex `portalmanager`) e il riferimento
(`pm_demo`):

| Verifica | Esito |
|---|---|
| Commesse totali | 1.064 (1.062 reali + 2 segnaposto legittimi) |
| Codici combacianti con demo | **1.062 / 1.062** |
| **Identità su tutti i 29 campi** | **1.062 / 1.062** |
| `client_id` risolto per ragione sociale | 1.062 / 1.062 |
| Clienti / sedi | 305 / 296 (identici a demo) |
| Alias progetto / fascia | 1 / 8 (identici a demo) |
| Segnaposto residui | 2, entrambi privi di controparte reale, con i loro 3 rapporti intatti |
| Rapporti di intervento orfani | **0** |
| Rapporti rimappati su commesse reali | 67.783 su 67.786 |
| Commesse con date per il Gantt | 1.062 (come demo) |
| Top commesse per rapporti | `WTS_3016` 6.805, `WTS_3201` 3.606, `WTS_3053` 3.225 — corrispondono a demo |
| `ProjectModel::listAll()` sul DB corretto | 1.064 righe, 37 campi, 0 righe fuori dai 29 campi standard |

## 6. QA funzionale — assorbimento automatico

Simulazione dell'import su DB sporco (segnaposto e commesse reali coesistenti),
replicando testualmente la logica di `import_commesse.php` v1.8.41:

| Verifica | RUN1 | RUN2 |
|---|---|---|
| Commesse prima / dopo | 778 → 1.064 | 1.064 → 1.064 |
| Segnaposto prima / dopo | 778 → 2 | 2 → 2 |
| Segnaposto assorbiti | **776** | **0** (idempotente) |
| Contratti DGB con righe duplicate | **0** | **0** |
| Rapporti orfani | **0** | **0** |

## 7. Sicurezza

- [x] Tutte le query dell'import usano prepared statement con placeholder
- [x] `absorb()` vincolato a `project_code LIKE 'DGB-%' AND id <> $realId`: non può
      eliminare una commessa reale né la riga appena scritta
- [x] La `DELETE` della migration porta il doppio predicato (join sulla tabella
      ponte **e** `project_code LIKE 'DGB-%'`) come rete di sicurezza
- [x] Rimappatura sempre precedente alla cancellazione: nessun riferimento resta
      orfano in alcun istante
- [x] `client_locations` protetto da `INSERT IGNORE` sulla chiave unica
- [x] Nessun ID interno trasferito fra istanze: solo chiavi naturali

## 8. Documentazione

- [x] `docs/CHANGELOG.md` — diagnosi, tabella comparativa, causa strutturale
- [x] `docs/TECHNICAL_DESIGN_v1_8_41.md` — genesi dei segnaposto, chiave di
      riconciliazione, schema del rimappaggio, vincoli di integrità
- [x] `docs/DEPLOYMENT_v1_8_41.md` — backup obbligatorio, verifica, rollback
- [x] `docs/MANUALE_ADMIN_v1_8_41.md`
- [x] `docs/MANUALE_UTENTE_v1_8_41.md`
- [x] `docs/RELEASE_CHECKLIST_v1_8_41.md` — questo documento

## 9. Esclusioni motivate

- **`cm_team`** (34 righe): dipende da `employees.id`, anagrafiche non
  sovrapponibili fra le istanze (217 id coincidenti su 286). Il trasferimento
  avrebbe prodotto associazioni errate. Dato derivabile: si rigenera da
  *Sincronizza team dai rapporti*.
- **`cm_professionals`** (247 contro 246) e **`cm_import_batches`** (storico locale
  degli import): fuori dal perimetro della richiesta, lasciati invariati.
- **`demo_portalmanager`**: già corretto, non deve ricevere questa release.
