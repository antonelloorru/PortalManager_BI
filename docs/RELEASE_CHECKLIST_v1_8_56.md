# Release Checklist — PortalManager v1.8.56

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.55.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.56` |
| `dgb_activities.php` | ROOT, **modificato** | OK |
| `app/Version.php` | modificato | OK |
| altri 5 ROOT + 7 in `app/` | invariati da v1.8.55 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.56**
- [x] Release solo applicativa: nessuna variazione di schema né di dati

## 2. Filtri implementati

- [x] Tecnico (ricerca parziale, con elenco a discesa dei nominativi presenti)
- [x] Tipo di anomalia
- [x] Severità
- [x] Dal giorno / Al giorno
- [x] Conteggio delle segnalazioni corrispondenti accanto ai pulsanti
- [x] Le schede di riepilogo preservano gli altri filtri attivi
- [x] `route_slug_field()` primo elemento del form GET (regola v1.8.42)

## 3. QA — i filtri, verificati per proprietà

Controlli su proprietà che devono valere per costruzione, non su casi noti.

| Filtro | Righe |
|---|---|
| nessuno | 2.240 |
| tipo ore_duplicate | 1.459 |
| tipo ore_giornaliere | 781 |
| severità alta | 1.473 |
| severità media | 767 |
| tecnico "Mirko Vadi" | 661 |
| dal 2025-01-01 | 1.344 |
| anno 2025 | 863 |
| tecnico + alta + 2025 | 182 |

| Verifica | Esito |
|---|---|
| Somma per severità = totale | 1.473 + 767 = **2.240** OK |
| Somma per tipo = totale | 1.459 + 781 = **2.240** OK |
| I filtri restringono | **SI** |
| Filtro tecnico: nominativi distinti nel risultato | **1** |
| Filtro data: righe fuori intervallo | **0** |

- [x] La partizione che somma al totale è un controllo più forte della verifica
      di un caso noto: se un filtro perdesse righe o le contasse due volte, la
      somma non tornerebbe

## 4. QA — export allineato al video

Funzioni `$anomWhere`, `$anomOrder`, `$anomCols` e `$anomRow` **estratte dal file
di release** ed eseguite.

| Verifica | Esito |
|---|---|
| Colonne dichiarate | **9**, identiche fra video ed export |
| Caso di prova (tecnico + severità alta) | **546** righe corrispondenti |
| Righe a video | 500 (limite) |
| **Righe nell'export** | **546 — tutte** |
| Prime 20 righe identiche fra video ed export | **SI**, colonna per colonna |
| Righe con numero di celle diverso da 9 | **0** |
| XLSX | 23.172 byte, firma `PK` |
| CSV: header identici alle colonne dichiarate | **SI** |
| CSV: righe rilette | 546 su 546 attese |
| Export senza filtri | 2.240 righe (a video 500) |

- [x] **Una sola funzione** costruisce la clausola per tabella ed export: due
      funzioni separate si disallineerebbero al primo filtro aggiunto a una sola
      delle due, producendo un file plausibile con i dati sbagliati
- [x] Il conteggio totale usa `COUNT(*)` e non `count()` sull'array, che
      restituirebbe 500 e nasconderebbe la differenza
- [x] La differenza fra righe a video ed esportate è **dichiarata**
      nell'intestazione della tabella

## 5. QA — allineamento delle colonne

- [x] Aggiunte a video **Tipo** e **Commesse coinvolte**, che comparivano solo
      nell'export
- [x] Motivazione: il tipo serve quando il filtro è disattivato e l'elenco mescola
      due famiglie; le commesse coinvolte qualificano la gravità dell'anomalia
- [x] Le nove colonne sono ora identiche in entrambe le direzioni

## 6. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_demo` | 4 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_demo` | 4 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_demo` | 4 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c56` fresco (132 tabelle) | 400 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c56` | 400 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c56` | 400 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Conteggio statement consolidato: **399 → 400**

## 7. Sicurezza

- [x] Tutti i filtri passano da placeholder di prepared statement
- [x] Le date sono validate contro `^\d{4}-\d{2}-\d{2}$` prima dell'uso
- [x] Nessun nome di colonna o di tabella deriva da input utente
- [x] Export: buffer svuotati, `zlib.output_compression` disattivato, `exit` dopo
      l'emissione del binario
- [x] Ogni export registrato nell'event log con il numero di righe

## 8. Documentazione

- [x] `CHANGELOG.md` — filtri, allineamento, verifica del caso decisivo
- [x] `TECHNICAL_DESIGN_v1_8_56.md` — clausola condivisa, limite a video e non in
      export, verifica per proprietà
- [x] `DEPLOYMENT_v1_8_56.md` — verifica con più di 500 righe
- [x] `MANUALE_ADMIN_v1_8_56.md`, `MANUALE_UTENTE_v1_8_56.md`
- [x] `RELEASE_CHECKLIST_v1_8_56.md` — questo documento

## 9. Nota

Il limite di 500 righe a video resta, ed è una scelta: una pagina con migliaia di
righe è lenta e illeggibile. La differenza rispetto all'export è dichiarata a
schermo perché sia nota prima di aprire il file, non scoperta dopo.
