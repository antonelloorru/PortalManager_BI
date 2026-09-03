# Release Checklist — PortalManager v1.8.62

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.61.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.62` |
| `import_commesse_db.php` | ROOT, **modificato** | OK |
| `app/Version.php` | modificato | OK |
| 6 ROOT + 5 in `app/` | invariati da v1.8.61 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.62**
- [x] Release solo applicativa: nessuna variazione di schema né di dati

## 2. Diagnosi della segnalazione

- [x] Messaggio riprodotto: la verifica usava `CommesseSync::REQUIRED`
      (`code`, `name`) su **una sola tabella**, quella di `source_table`
- [x] Era il meccanismo della **v1.8.45**, rimasto agganciato dopo che la v1.8.46
      lo aveva sostituito con `SyncDatasets`
- [x] Verificato sul dump: `forms_contract` **ha** sia `code` sia `name`
- [x] **Falso allarme**: il messaggio riguardava un parametro privo di
      significato, non un problema reale
- [x] Il difetto simmetrico: la verifica non diceva nulla sulle altre **tredici**
      tabelle, quelle che servono davvero

## 3. QA — la nuova verifica sul dump reale

| Verifica | Esito |
|---|---|
| Oggetti nello schema | 102 |
| Tabelle richieste dai dataset | **14** |
| Tabelle assenti | **0** |
| **Dataset utilizzabili** | **8 / 8** |

| Dataset | Tabelle | Colonne | Esito |
|---|---|---|---|
| Commesse / Progetti | 4 | 30 | ok |
| Costi orari delle fasce | 1 | 7 | ok |
| Full cost per operatore | 1 | 5 | ok |
| Anagrafica professionisti | 2 | 8 | ok |
| Tariffe di contratto | 3 | 6 | ok |
| Allocazioni pianificate | 4 | 8 | ok |
| Operazioni economiche di commessa | 3 | 15 | ok |
| Rapporti di intervento | 9 | 27 | ok |

## 4. QA — la diagnosi localizza il difetto

Simulata l'assenza di `forms_um_rate_for_contract`:

- [x] **7 dataset su 8** restano utilizzabili
- [x] L'errore è attribuito al **solo** dataset *Tariffe di contratto*
- [x] Il nome della tabella mancante è riportato

## 5. Scelte di progetto

- [x] **Verifica per esecuzione**: ogni query girata con `LIMIT 0` sulla sorgente.
      Un controllo sui nomi di colonna non intercetta join verso tabelle
      rinominate, alias errati o funzioni non supportate
- [x] Confronto fra colonne prodotte e mappatura dichiarata: una query può essere
      valida e produrre nomi diversi, e in quel caso la sincronizzazione
      scriverebbe nulla
- [x] Tre esiti distinti — *tabelle mancanti*, *query non eseguibile*, *colonne
      non prodotte* — in ordine di gravità
- [x] **Un fallimento parziale non è un blocco**: il riquadro dichiara che i
      dataset validi funzionano comunque, coerentemente con il comportamento
      della sincronizzazione completa (v1.8.57)

## 6. Correzione in corso di collaudo

- [x] La prima stesura della migration allineava `cm_source_db.source_table`
- [x] La tabella è introdotta dalla v1.8.45: su un'installazione priva l'UPDATE
      **interrompeva la migration** (verificato su `pm_demo`)
- [x] Il tentativo con `PREPARE`/`EXECUTE` ha fallito il collaudo: il tokenizer
      non li gestisce come statement singoli (3 errori su 9)
- [x] Soluzione adottata: **non fare la modifica**. `source_table` non è più usato
      dalla verifica, quindi allinearlo era superfluo oltre che rischioso

## 7. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_demo` | 4 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_demo` | 4 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_demo` | 4 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c62` fresco | 441 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c62` | 441 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c62` | 441 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** (un `;` in commento corretto in corsa)
- [x] Conteggio statement consolidato: **440 → 441**

## 8. Documentazione

- [x] `CHANGELOG.md` — falso allarme spiegato, nuova verifica, prova di
      localizzazione
- [x] `TECHNICAL_DESIGN_v1_8_62.md` — verifica per esecuzione, tre livelli di
      diagnosi, fallimento parziale, perché l'UPDATE è stato rimosso
- [x] `DEPLOYMENT_v1_8_62.md` — verifica attesa, cosa fare se un dataset è in
      difetto, nota su `source_table`
- [x] `MANUALE_ADMIN_v1_8_62.md`, `MANUALE_UTENTE_v1_8_62.md`
- [x] `RELEASE_CHECKLIST_v1_8_62.md` — questo documento

## 9. Nota di metodo

Un test che fallisce quando il sistema funziona è il caso peggiore per una
diagnostica: o si perde tempo a cercare un problema inesistente, o si impara a
ignorare i messaggi di quella pagina — e allora non si notano nemmeno quelli veri.

La verifica era rimasta indietro di sedici release rispetto al meccanismo che
avrebbe dovuto controllare. Vale la pena chiedersi, quando si sostituisce un
componente, quali diagnostiche lo osservavano.

## 10. Aperto

- `CommesseSync` resta nel codice per la parte residua del vecchio flusso di
  import da singola tabella. Non è più usato dalla verifica, ma la sua presenza
  mantiene due meccanismi paralleli: vale la pena decidere se il vecchio flusso
  serva ancora.
- `cm_source_db.source_table` è ora inerte per la verifica. Se il vecchio flusso
  venisse dismesso, il campo potrebbe essere rimosso.
