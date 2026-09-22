# Release Checklist — PortalManager v1.8.45

Policy **zero-omission**: ogni voce verificata con evidenza.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.45` |
| `import_commesse_db.php` | ROOT, esteso | OK |
| `app/SourceDb.php` | **nuovo** | OK |
| `app/CommesseSync.php` | **nuovo** | OK |
| `import_employees_xlsx.php` | ROOT, invariato da v1.8.43 | OK |
| `app/ListFilter.php` | invariato da v1.8.44 | OK |
| `app/EmployeeImportSchema.php` | invariato da v1.8.43 | OK |
| `app/Version.php` | modificato | OK |
| `sql/migration_v1_8_45.sql` | nuovo | n/a |
| `sql/upgrade_1_7_56_to_1_8_45.sql` | nuovo, cumulativo | n/a |
| `docs/` × 6 | nuovi | n/a |

- [x] Pacchetto cumulativo e auto-consistente
- [x] Dipendenze dichiarate nella guida di deployment: senza i due file nuovi
      `import_commesse_db.php` non funziona
- [x] ZIP con separatore forward-slash; ZIP precedente rimosso dagli output
- [x] File completi e già patchati, nessuno snippet o diff

## 2. Versionamento coeso

- [x] `VERSION` = `1.8.45`
- [x] `PM_VERSION` = `1.8.45`
- [x] `app_settings` = `1.8.45`, verificato su `pm_s2`

## 3. QA — cifratura delle credenziali

| Verifica | Esito |
|---|---|
| Password in chiaro assente dal cifrato | **NO (corretto)** |
| Round-trip cifra/decifra | **OK** |
| Due cifrature dello stesso valore differiscono (IV casuale) | **SI** |
| Cifrato manomesso rifiutato (GCM autenticato) | **SI** |

## 4. QA — vincoli di sola lettura

| Istruzione tentata | Esito |
|---|---|
| `UPDATE` | **respinta** |
| `DELETE` | **respinta** |
| `DROP TABLE` | **respinta** |
| `SELECT` seguito da un secondo statement | **respinta** |
| Identificatore `contract; DROP TABLE x` | **respinto** |

## 5. QA — mappatura e sincronizzazione

Sorgente simulata: tabella `contract` con le colonne reali, 4 righe di cui una
marcata eliminata, più una colonna non mappata. Destinazione: dump reale
`portalmanager_db.sql` con 778 segnaposto DGB.

| Verifica | Esito |
|---|---|
| Colonne nella sorgente / usate | 20 / **19** |
| Colonna non mappata esclusa | **SI** |
| Anteprima: lette / da inserire / eliminate saltate | 4 / 3 / 1 |
| Anteprima non scrive nulla | **SI** |
| Sincronizzazione: lette / nuove / aggiornate / saltate | 4 / 3 / 0 / 1 |
| Campi verificati sul record scritto | 13, **discordanti 0** |
| Stato `CLOSED` convertito in `Chiusa` | **SI** |
| Commessa eliminata non importata | **SI** |
| Cliente creato in anagrafica | **SI** |
| Segnaposto `DGB-77` assorbito | **SI** (3 assorbiti) |
| Rapporti di intervento orfani | **0** |
| Data `2020-01-15 00:00:00.000` convertita | `2020-01-15` |

## 6. QA — idempotenza e opzioni

| Verifica | Esito |
|---|---|
| Seconda esecuzione: nuove / aggiornate | **0 / 3** |
| Totale commesse invariato | **SI** (778 → 778) |
| Con `include_deleted`: la riga eliminata viene importata | **SI** |

## 7. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_s1` | 7 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_s1` | 7 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_s1` | 7 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_s2` fresco | 320 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_s2` | 320 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_s2` | 320 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] `CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS`,
      `ON DUPLICATE KEY UPDATE`
- [x] `cm_source_db` creata e verificata su `pm_s2`
- [x] Conteggio statement consolidato: **316 → 320**

## 8. Sicurezza

- [x] Password cifrata AES-256-GCM con chiave derivata da `APP_SECRET`, che
      risiede fuori dal database
- [x] Password mai restituita al browser; campo vuoto in modifica = invariata
- [x] Sessione read-only dove il dialetto lo consente, in `try/catch`
- [x] `query()` ammette solo `SELECT`/`WITH`, rifiuta query multiple
- [x] Identificatori validati con espressione regolare e quotati per dialetto
- [x] Azioni riservate a chi ha il permesso di creazione sulla pagina
- [x] CSRF su tutti i form
- [x] Ogni operazione registrata nell'event log
- [x] Documentata la raccomandazione di un'utenza dedicata di sola lettura: i
      vincoli applicativi sono difesa in profondità, non un sostituto

## 9. Robustezza

- [x] `availableDrivers()` filtra sull'estensione PDO caricata; se nessuna è
      presente la pagina lo dichiara indicando cosa abilitare
- [x] Query non bufferizzata su MySQL: sorgenti grandi non saturano la memoria
- [x] Commit ogni 500 righe
- [x] Timeout configurabile, limitato fra 3 e 60 secondi
- [x] Colonne mancanti tollerate salvo `code` e `name`, con messaggio esplicito
- [x] Confronto colonne case-insensitive, per i dialetti che non preservano il caso
- [x] Anteprima e sincronizzazione usano la **stessa** funzione, così non possono
      divergere

## 10. Documentazione

- [x] `CHANGELOG.md`, `TECHNICAL_DESIGN_v1_8_45.md`, `DEPLOYMENT_v1_8_45.md`,
      `MANUALE_ADMIN_v1_8_45.md`, `MANUALE_UTENTE_v1_8_45.md`, questa checklist
- [x] Prerequisiti documentati: driver PDO, raggiungibilità di rete, utenza di
      sola lettura, dipendenza da `.env.php`
- [x] Avvertenza esplicita: rigenerando `.env.php` le password salvate non sono
      più decifrabili e vanno reinserite

## 11. Fuori perimetro

Non è stata introdotta l'esecuzione pianificata: la sincronizzazione è manuale.
Se servisse ricorrente, la via naturale è un'attività dell'Utilità di
pianificazione di Windows che invochi la pagina, o un endpoint dedicato protetto
da token. Va concordato prima di implementarlo, perché comporta una superficie di
accesso non presidiata da sessione utente.
