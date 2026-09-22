# Release Checklist — PortalManager v1.8.47

Policy **zero-omission**: ogni voce verificata con evidenza.
Base di partenza: **v1.8.46**, versione in produzione.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.47` |
| `manage_projects.php` | ROOT, riscritto | OK |
| `app/ProjectModel.php` | modificato | OK |
| `app/Version.php` | modificato | OK |
| `sql/migration_v1_8_47.sql` | nuovo | n/a |
| `sql/upgrade_1_7_56_to_1_8_47.sql` | nuovo, cumulativo | n/a |
| `docs/` × 6 | nuovi | n/a |

- [x] In `app/` **solo** i file modificati. `RateResolver.php` e
      `PrefixResolver.php`, copiati in sandbox per eseguire il model nei test,
      sono stati **rimossi prima del packaging**
- [x] ZIP forward-slash; ZIP precedente rimosso dagli output
- [x] File completi e già patchati, nessuno snippet o diff

## 2. Versionamento coeso

- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.47**, verificato su `pm_w2`
- [x] Nessuna riscrittura di versioni già rilasciate

## 3. QA — interfaccia

- [x] Barra strumenti in testa: nuova commessa, due export, contatore
- [x] Pannello inserimento chiuso di default, apribile dal pulsante e dal titolo
- [x] Pannello inserimento **riaperto** dopo un errore di validazione
      (`$_SESSION['reopen_new']`), così il messaggio resta azionabile
- [x] Pannello filtri chiuso di default ma **aperto automaticamente** quando ci
      sono filtri attivi, con il conteggio nel titolo
- [x] Entrambi basati su `<details>`: funzionano senza JavaScript
- [x] Il conteggio dei filtri attivi esclude l'ordinamento, che non restringe
- [x] `route_slug_field()` primo elemento del form GET (regola v1.8.42)
- [x] Riga vuota differenziata: "nessuna corrispondenza ai filtri" con link di
      azzeramento, oppure "nessuna commessa in archivio"

## 4. QA — filtri

Verifica su `pm_demo` (1.062 commesse) eseguendo `ProjectModel::listAll()` del
file di release e confrontando ogni risultato con un conteggio **indipendente**
calcolato in SQL.

| Verifica | Esito |
|---|---|
| Filtri provati singolarmente | **38 / 38 corrispondenti** |
| Combinazione APERTA + anomalie aperte | 555 → 126, restringe correttamente |
| Intervallo su valore (min e max insieme) | model 226 = sql 226 |
| Ordinamenti eseguiti senza errore | **14 / 14**, tutti 1.062 righe |
| Ordinamento realmente applicato | crescente −4.324,50 · decrescente 1.957.963,72 |

Filtri nuovi verificati: sigla, cliente testuale, testo nelle descrizioni,
presenza collegamento, riconciliazione DGB, finestra su data fine, senza data
fine, in scadenza entro N giorni, soglie su margine, residuo e consuntivato,
margine negativo, fido superato, frequenza e data di prima fatturazione, batch.

## 5. QA — export XLSX e CSV

Collaudo sul file di release: header e funzione di conversione **estratti dal
sorgente** ed eseguiti, non riscritti.

| Verifica | Esito |
|---|---|
| Header standard nel file | **29** |
| XLSX senza filtri | 203.123 byte, firma `PK` |
| XLSX aperto con openpyxl | foglio "Lista commesse", 1.063 righe × 29 colonne |
| XLSX come archivio | ZIP valido, 7 parti |
| CSV | BOM presente, separatore `;` |
| Header CSV identici allo standard | **SI** |
| Righe con numero di celle diverso da 29 | **0** |

**L'export rispetta i filtri** — sei combinazioni provate:

| Filtro | listAll | righe nel file |
|---|---|---|
| nessuno | 1.062 | 1.062 |
| stato APERTA | 555 | 555 |
| con anomalie bloccanti | 139 | 139 |
| margine negativo | 221 | 221 |
| valore fra 1k e 5k | 226 | 226 |
| APERTA + anomalie aperte | 126 | 126 |

**I valori coincidono col database**: confronto cella per cella su una commessa
con tutti i campi valorizzati — 11 celle verificate, **0 discordanti**.

**Robustezza del CSV**: testo contenente virgolette, punto e virgola e andate a
capo dentro la cella viene riletto identico.

- [x] Nessuna correzione necessaria: gli export erano già funzionanti

## 6. Quality Assurance SQL

DB creati da zero dal dump reale `portalmanager_db.sql`.

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_w1` | 5 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_w1` | 5 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_w1` | 5 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_w2` fresco | 327 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_w2` | 327 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_w2` | 327 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] `ADD INDEX IF NOT EXISTS`, `ON DUPLICATE KEY UPDATE`
- [x] Indici `idx_proj_*` presenti dopo la migration: **20** (12 preesistenti + 8)
- [x] Conteggio statement consolidato: **325 → 327**

## 7. Sicurezza

- [x] Tutti i 38 filtri passano da placeholder di prepared statement
- [x] Ordinamento risolto tramite mappa a costanti con fallback: un valore non
      previsto ricade sul predefinito, non finisce nella query
- [x] Whitelist duplicata lato pagina per non offrire opzioni non valide, ma la
      difesa effettiva è nel model
- [x] Output HTML sempre via `h()`; collegamento esterno con `rel="noopener"`
- [x] Permessi verificati separatamente per vista, creazione ed export
- [x] Export: buffer svuotati, `zlib` disattivato, `exit` dopo l'emissione

## 8. Documentazione

- [x] `CHANGELOG.md` — problema, interfaccia, filtri, etichette, esito export
- [x] `TECHNICAL_DESIGN_v1_8_47.md` — gerarchia della pagina, scelta di
      `<details>`, conteggio filtri, copertura, sicurezza, due registri di
      etichette, sequenza dell'export
- [x] `DEPLOYMENT_v1_8_47.md` — verifica puntuale, nota sugli indici, rollback
- [x] `MANUALE_ADMIN_v1_8_47.md`, `MANUALE_UTENTE_v1_8_47.md`
- [x] `RELEASE_CHECKLIST_v1_8_47.md` — questo documento

## 9. Nota

Il pannello filtri si apre da solo quando ci sono filtri attivi. È una scelta
deliberata: un elenco filtrato con il pannello chiuso è indistinguibile da un
elenco incompleto, ed è il modo più comune in cui un filtro dimenticato diventa
una segnalazione di dati mancanti.
