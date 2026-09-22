# Release Checklist — PortalManager v1.8.44

Policy **zero-omission**: ogni voce verificata con evidenza.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — contiene `1.8.44` |
| `app/ListFilter.php` | **corretto in questa release** | OK |
| `import_employees_xlsx.php` | ROOT, invariato da v1.8.43 | OK |
| `app/EmployeeImportSchema.php` | invariato da v1.8.43 | OK |
| `app/Version.php` | modificato | OK |
| `sql/migration_v1_8_44.sql` | nuovo | n/a |
| `sql/upgrade_1_7_56_to_1_8_44.sql` | nuovo, cumulativo | n/a |
| `docs/` × 6 | nuovi | n/a |

- [x] Pacchetto cumulativo: include i file della v1.8.43, invariati, così è
      auto-consistente anche se quella release non fosse stata applicata
- [x] ZIP con separatore forward-slash
- [x] ZIP della versione precedente rimosso da `/mnt/user-data/outputs/`
- [x] File completi e già patchati, nessuno snippet o diff

## 2. Versionamento coeso

- [x] `VERSION` = `1.8.44`
- [x] `app/Version.php` → `PM_VERSION` = `1.8.44`
- [x] `app_settings` = `1.8.44`, verificato su `pm_r2`
- [x] Nessuna riscrittura di versioni già rilasciate

## 3. Analisi del difetto

- [x] Causa individuata: DataTables **stacca dal DOM** le righe fuori pagina, non
      le nasconde. `tbody tr` ne restituisce quindi `pageLength`, non il totale
- [x] Riconosciuto il limite della correzione v1.8.43: rimuoveva il predicato
      `lf-hidden` ma iterava su un insieme già ridotto a monte
- [x] Riconosciuta la lacuna del test v1.8.43: il DOM simulato conteneva tutte le
      righe, assumendo implicitamente l'assenza di paginazione
- [x] Ricerca **esaustiva**: 13 pagine usano DataTables, **10 usano anche
      ListFilter** ed erano tutte affette
- [x] Verificato che anche `applyFilters()` soffriva dello stesso troncamento

## 4. QA funzionale

Test che riproduce la condizione reale — 286 righe totali, 25 nel DOM, API
DataTables simulata — con le funzioni **estratte dal file di release** ed
eseguite, non riscritte.

| Scenario | Righe | Esito |
|---|---|---|
| Lettura diretta dal DOM (comportamento precedente) | 25 | difetto riprodotto |
| `getRowNodes()` — nodi recuperati | 286 | **OK** |
| Export ambito "tutti" | 286 (atteso 286) | **OK** |
| Export ambito "filtrate" | 95 (atteso 95) | **OK** |
| Include righe oltre la prima pagina | SI | **OK** |
| Fallback senza DataTables | 25, cioè quelle nel DOM | **OK** |

- [x] Intestazioni preservate, prima e ultima riga corrette
- [x] Non regressione sul test v1.8.43 (tabella senza paginazione): 2 righe
      filtrate, 5 complessive, righe di servizio escluse

## 5. Quality Assurance SQL

DB creati da zero dal dump reale `portalmanager_db.sql`.

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_r1` | 4 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_r1` | 4 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_r1` | 4 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_r2` fresco | 316 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_r2` | 316 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_r2` | 316 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file SQL
- [x] Conteggio statement consolidato: **315 → 316**

## 6. Sicurezza e robustezza

- [x] Rilevamento di DataTables a runtime, non all'inizializzazione: ListFilter
      viene creato prima della DataTable e un controllo anticipato fallirebbe
- [x] `try/catch` sull'intero rilevamento: le versioni di DataTables differiscono
      nell'esporre `$.fn.dataTable`, e un errore lì non deve impedire l'export
- [x] Ricaduta sul DOM quando DataTables è assente: pagine non paginate invariate
- [x] Nessuna nuova dipendenza: si usa l'istanza jQuery/DataTables già caricata
- [x] Nessuna chiamata al server aggiuntiva
- [x] Nessuna modifica a schema, dati o autorizzazioni

## 7. Documentazione

- [x] `docs/CHANGELOG.md` — causa, perché la v1.8.43 non bastava, estensione
- [x] `docs/TECHNICAL_DESIGN_v1_8_44.md` — convivenza DataTables/ListFilter,
      correzione, cautele, limitazione nota sulla paginazione
- [x] `docs/DEPLOYMENT_v1_8_44.md` — verifica con conteggio righe
- [x] `docs/MANUALE_ADMIN_v1_8_44.md`
- [x] `docs/MANUALE_UTENTE_v1_8_44.md`
- [x] `docs/RELEASE_CHECKLIST_v1_8_44.md` — questo documento

## 8. Limitazione nota, dichiarata

DataTables calcola la propria impaginazione sui dati che gestisce e non tiene
conto della classe `lf-hidden` applicata da ListFilter. Conteggio righe ed export
sono corretti su tutto l'insieme; la navigazione fra pagine continua a mostrare
tutte le righe. Documentato nel Technical Design e nel Manuale Amministratore, con
l'indicazione di usare il campo di ricerca della tabella per una lettura a schermo
del solo insieme filtrato.

## 9. Nota di metodo

Il difetto è sfuggito alla v1.8.43 perché il test, pur usando la funzione reale,
la eseguiva su un DOM che conteneva tutte le righe. Verificare il codice giusto
non basta se lo scenario non riproduce la condizione di produzione: qui il test
è stato ricostruito attorno alla paginazione, che è l'elemento che rompeva
l'assunzione.
