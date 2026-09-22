# Release Checklist — PortalManager v1.8.66

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.65.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.66` |
| `app/Version.php` | modificato | OK |
| 6 ROOT + 6 in `app/` | invariati da v1.8.65 | OK |
| `sql/migration_v1_8_66.sql` | nuovo | n/a |
| `sql/upgrade_1_7_56_to_1_8_66.sql` | **corretto anche nei blocchi v1.8.50 e v1.8.51** | n/a |
| `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.66**

## 2. Diagnosi

- [x] Errore riprodotto creando lo scenario reale: tabella `utf8mb4_general_ci`
      in un database `utf8mb4_unicode_ci`
- [x] Causa: le tabelle d'appoggio ereditavano la collation di **default del
      database**, divergente da quella di `cm_intervention_reports`
- [x] **Il problema era più ampio della segnalazione**: sul consolidato, **sei**
      statement in errore e non due — deduplica v1.8.50 e v1.8.51,
      riconciliazione DGB, vista di marginalità, e altri due
- [x] Ogni join fra `cm_intervention_reports` e altra tabella falliva
- [x] `cm_intervention_reports` è **l'unica** tabella divergente, verificato su
      `information_schema`

## 3. Correzione

- [x] **Allineamento della collation della tabella**, non `COLLATE` sui singoli
      join: correggere i join lascerebbe il problema alla query successiva
- [x] Target `utf8mb4_unicode_ci`: la collation dichiarata in tutte le
      `CREATE TABLE` delle migration e default del database
- [x] Tabelle d'appoggio con `CREATE TABLE ... AS SELECT`: **ereditano** tipo e
      collation dalla colonna sorgente, quindi corrette per costruzione su
      qualunque installazione
- [x] Cablare `general_ci` è stato scartato: avrebbe rotto sull'installazione
      opposta

## 4. L'ordine era parte della correzione

- [x] Primo tentativo con l'`ALTER` nel blocco v1.8.66, in **coda** al
      consolidato: **stessi 6 errori**, perché gli statement falliscono al #337 e
      #404 e l'`ALTER` arrivava al #479
- [x] Spostato **in testa al file**: da 6 errori a **zero**
- [x] Difetto che nessuna analisi statica avrebbe rivelato: la correzione era
      giusta, la posizione no

## 5. QA — collaudo sullo scenario riprodotto

| Test | DB | Esito |
|---|---|---|
| Consolidato, **prima** della correzione | collation mista | **6 errori** |
| Consolidato, dopo | collation mista | **480 stmt, err=0** |
| Consolidato RUN2 (idempotenza) | collation mista | 480 stmt, **err=0** |
| Consolidato splitter naive | DB normale | 480 stmt, **err=0** |
| Consolidato tokenizer reale | DB normale | 480 stmt, **err=0** |
| Migration RUN1 | collation mista | 14 stmt, **err=0** |
| Migration RUN2 (idempotenza) | `pm_demo` | 15 stmt, **err=0** |
| Migration RUN3 naive | collation mista | 14 stmt, **err=0** |

Esito funzionale sullo scenario di prova:

| | Prima | Dopo |
|---|---|---|
| Righe | 5 | **3** |
| Grane distinte | 2 | 3 |
| Righe senza grana | 1 | **0** |
| Vincolo `uq_ir_source_uid` | assente | **applicato** |
| Tabelle temporanee residue | 2 | **0** |
| Collation divergenti | 1 | **0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file

## 6. Conseguenza sui dati

- [x] Le v1.8.50 e v1.8.51 si interrompevano sulla `DELETE`, che **precede**
      l'`ADD UNIQUE KEY`: né la deduplica né il vincolo erano stati applicati
- [x] La protezione contro i duplicati, oggetto di due release, **non era mai
      entrata in funzione**
- [x] Le ore consuntivate possono **calare**: documentato nel deployment e nei
      manuali, con la query per ispezionare i duplicati prima di aggiornare
- [x] Criterio dichiarato: le ore possono diminuire, mai aumentare

## 7. Controllo permanente

- [x] `v_cm_collation_check` elenca le colonne di collegamento con collation
      divergente. Zero righe non garantisce che tutto funzioni, ma una riga
      garantisce che qualcosa fallirà
- [x] Indicato nel manuale come verifica dopo ogni importazione da dump esterni,
      che è il modo in cui una tabella acquisisce una collation diversa

## 8. Nota di metodo

La segnalazione riportava due errori. Riprodurre lo scenario ne ha mostrati sei:
gli altri quattro riguardavano statement più a valle, che l'esecuzione non aveva
ancora raggiunto.

**Una segnalazione è un campione, non l'inventario.** Correggere solo ciò che è
stato riportato avrebbe lasciato quattro difetti attivi, che sarebbero emersi uno
alla volta nelle esecuzioni successive.

## 9. Aperto

- Restano i punti della v1.8.63: collegamento fra divisione e tecnico, ed esame
  delle 137 commesse segnalate solo dal gestionale.
- Dopo questa migration va rilanciata la sincronizzazione in **scrittura**
  (v1.8.65), perché i dati potrebbero provenire da sole anteprime.
