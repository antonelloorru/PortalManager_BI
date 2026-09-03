# Release Checklist — PortalManager v1.8.51

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.50.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.51` |
| `import_intervention_reports.php` | ROOT, **allineato alla grana** | OK |
| `app/DgbSync.php` | **allineato alla grana + codice di riferimento** | OK |
| `app/SyncDatasets.php`, `DgbModel.php`, `MenuManager.php` | da v1.8.50 | OK |
| `app/SourceDb.php`, `DatasetSync.php`, `Router.php`, `Version.php` | da v1.8.49/50 | OK |
| 5 file ROOT | da v1.8.50 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.51**, verificato su `pm_c51`

## 2. Difetto della release precedente, riconosciuto e misurato

La v1.8.50 affermava che il vincolo `UNIQUE (source_uid)` proteggesse i due canali
non allineati. **L'affermazione non era stata verificata ed era falsa**: in
MySQL/MariaDB un UNIQUE su colonna nullable ammette più NULL.

Misurato con la v1.8.50 installata:

| Canale | Tre esecuzioni | Ore reali | Contabilizzate |
|---|---|---|---|
| `import_intervention_reports.php` | 3 righe | 8,00 | **24,00** |
| `DgbSync` | 3 righe | 5,00 | **15,00** |

- [x] La v1.8.50 aveva **peggiorato** la situazione per quei canali, avendo anche
      rimosso `uq_report_code`
- [x] È lo stesso meccanismo NULL che aveva reso inefficace `uq_cir_dgb_source`:
      stesso errore, due volte, sulla stessa tabella

## 3. QA — il trigger protegge i canali NON modificati

Prove eseguite con query **prive di `source_uid`**, identiche a quelle dei canali
non allineati.

| Verifica | Esito |
|---|---|
| Import da file ripetuto 3 volte | 1 riga, 8,00 ore — **invariato** |
| Sincronizzazione ripetuta 3 volte | 1 riga, 5,00 ore — **invariato** |
| Grana calcolata dal trigger (tecnico per nome) | `TRG_A_001#Rossi Mario` |
| Grana calcolata dal trigger (tecnico per id) | `TRG_B_001#51` |
| Due tecnici noti solo per nome | **2 righe, 7,00 ore** — non collassano |
| Doppione esplicito con grana identica | **respinto** dal vincolo |

## 4. QA — stabilità della grana

| Verifica | Esito |
|---|---|
| Grana prima dell'aggiornamento del tecnico | `TRG_A_001#Rossi Mario` |
| Grana dopo `UPDATE technician_id=99` | `TRG_A_001#Rossi Mario` — **invariata** |

- [x] Il trigger su UPDATE ricalcola solo se la grana è vuota: una chiave che
      cambia non è una chiave, e ricalcolarla farebbe rinascere il difetto alla
      sincronizzazione successiva

## 5. QA — i due canali allineati, con il codice reale

Query **estratte dai file di release** ed eseguite, non riscritte.

| Verifica | Esito |
|---|---|
| Segnaposto / parametri, import da file | **36 / 36** |
| Colonne dichiarate, DgbSync | **21**, query preparata contro il DB: OK |
| Import da file ripetuto 3 volte | 1 riga, 8,00 ore |
| Due tecnici + ripetizione di uno | 2 righe, 7,00 ore |
| Sincronizzazione ripetuta 3 volte | 1 riga, 5,00 ore |
| Codice scritto da DgbSync | `CAN_B_001` (era `DGB-<id>`) |
| `source_system` valorizzato | `dgb` / `xlsx` |

### Scenario originale della segnalazione

| Passo | Righe | Ore |
|---|---|---|
| dopo import da file | 1 | 6,00 |
| dopo sincronizzazione, stessa prestazione | **1** | **6,00** |

- [x] `eccedenza` = 0 su ogni canale in `v_cm_grana_per_canale`

## 6. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_g50` | 17 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_g50` | 17 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_g50` | 17 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c51` fresco (132 tabelle) | 379 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c51` | 379 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c51` | 379 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Trigger presenti dopo il consolidato: `trg_ir_grana_ins`, `trg_ir_grana_upd`
- [x] `source_uid` risulta `NOT NULL DEFAULT ''`
- [x] `DROP TRIGGER IF EXISTS` + `CREATE`, non `CREATE OR REPLACE`: quest'ultimo
      non è supportato da tutte le versioni di MariaDB in uso
- [x] Conteggio statement consolidato: **364 → 379**

## 7. Sicurezza e prerequisiti

- [x] Migration **distruttiva sui dati**: elimina duplicati. Backup indicato come
      prerequisito non negoziabile
- [x] Richiede il privilegio `TRIGGER`: documentato, con la verifica
      `SHOW TRIGGERS` e l'alternativa via phpMyAdmin
- [x] Fornita la query per ispezionare i duplicati prima di aggiornare
- [x] Criterio di accettazione dichiarato: le ore possono diminuire, mai aumentare
- [x] Avvertenza sul rollback: tornare alla v1.8.50 significa tornare ai canali
      che duplicano

## 8. Documentazione

- [x] `CHANGELOG.md` — riconoscimento esplicito dell'errore della v1.8.50, misura
- [x] `TECHNICAL_DESIGN_v1_8_51.md` — semantica NULL nei vincoli UNIQUE, scelta
      del trigger, catena di ripiego, stabilità della grana, codice inventato
- [x] `DEPLOYMENT_v1_8_51.md` — urgenza, privilegio TRIGGER, verifica per canale,
      effetto sui codici DGB
- [x] `MANUALE_ADMIN_v1_8_51.md`, `MANUALE_UTENTE_v1_8_51.md`
- [x] `RELEASE_CHECKLIST_v1_8_51.md` — questo documento

## 9. Nota di metodo

L'errore della v1.8.50 nasce da un'affermazione scritta senza verificarla: "il
vincolo li protegge dal duplicare". Sarebbe bastata la prova eseguita qui —
inserire tre volte la stessa riga con `source_uid` NULL — per smentirla in pochi
secondi.

La verifica è ora parte della checklist: **ogni affermazione su un comportamento
protettivo va accompagnata dalla prova che quel comportamento si verifica**, non
dal ragionamento che dovrebbe verificarsi.

## 10. Aperto, dichiarato

- I rapporti già presenti importati da DGB conservano il codice `DGB-<id>` finché
  non vengono risincronizzati. Una sincronizzazione completa dopo l'aggiornamento
  li allinea senza duplicare, ma finché non viene eseguita convivono due formati
  di codice.
- `tecnico_non_identificato` conta le righe con grana terminante in `#0`, cioè
  senza alcun riferimento al tecnico. Sui dati attuali è 0; se dovesse crescere,
  indica un tracciato in ingresso incompleto e va indagato prima che diventi una
  fonte di collisioni.
