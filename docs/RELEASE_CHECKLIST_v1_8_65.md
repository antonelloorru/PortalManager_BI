# Release Checklist — PortalManager v1.8.65

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.64.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.65` |
| `sync_commesse.php` | ROOT, **corretto** | OK |
| `app/Version.php` | modificato | OK |
| 5 ROOT + 6 in `app/` | invariati da v1.8.64 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.65**
- [x] Release solo applicativa: nessuna variazione di schema né di dati

## 2. Diagnosi

- [x] Il messaggio segnalato era **corretto**: descriveva un'anteprima realmente
      eseguita, non un errore
- [x] Causa: due `<button name="action">` nello **stesso form**. Il valore di un
      pulsante è trasmesso solo se il browser lo riconosce come *submitter*;
      altrimenti il server riceve il **primo** pulsante, che era `preview_all`
- [x] Casi in cui il submitter non è riconosciuto: Invio da tastiera, submit
      programmatico, click su elemento figlio del pulsante
- [x] Lo stesso difetto era presente sul form di import CSV
- [x] Il pattern corretto era **già in uso** nella stessa pagina, sui pulsanti
      per singolo dataset

## 3. QA — trasmissione dell'azione

Form estratti dal file di release e simulati nei due modi di invio.

| Form | Con submitter | Senza submitter | Esito |
|---|---|---|---|
| Anteprima completa | `preview_all` | `preview_all` | **STABILE** |
| Sincronizza tutto | `sync_all` | `sync_all` | **STABILE** |

Confronto con il comportamento precedente:

| | Con submitter | Senza submitter |
|---|---|---|
| form unico, due pulsanti | `sync_all` | **`preview_all`** — il difetto |

- [x] `sync_all` raggiungibile anche senza submitter: **SI**
- [x] Form con più pulsanti `action` residui nel file: **0**

## 4. Correzioni applicate

- [x] Sincronizzazione completa: **due form separati** con campo nascosto
- [x] Import CSV: campo nascosto impostato dal pulsante premuto — i form non si
      possono separare perché condividono il campo file
- [x] Il valore predefinito del campo CSV è quello **non distruttivo**
      (`preview_csv`): senza JavaScript l'esito è un'anteprima, non una scrittura
      non voluta
- [x] Lettura esplicita lato server: `$dry = ($action === 'preview_all')` e non
      `!== 'sync_all'`, che tratterebbe un valore imprevisto come anteprima
      fallendo in silenzio proprio quando la trasmissione è andata storta

## 5. Esito inequivocabile

- [x] Etichetta nel titolo: **ANTEPRIMA** arancione o **SCRITTURA** verde
- [x] Testo dell'avviso riscritto: *«Questa era un'anteprima…»* con l'indicazione
      di quale pulsante usare per scrivere davvero
- [x] Modalità registrata nell'**event log**, per sapere a posteriori che cosa è
      stato eseguito

## 6. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_demo` | 4 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_demo` | 4 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_demo` | 4 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c65` fresco | 466 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c65` | 466 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c65` | 466 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Conteggio statement consolidato: **465 → 466**

## 7. Nota di metodo

Il difetto è nato scrivendo il riquadro della sincronizzazione completa (v1.8.57)
senza riusare un pattern **già presente poche righe più sotto** nella stessa
pagina.

Non ha prodotto errori per otto release: si manifestava solo quando il browser
non identificava il submitter, condizione che dipende da come l'utente interagisce
con la pagina. È il tipo di difetto che sfugge al collaudo funzionale e si scopre
in uso reale.

Vale la pena, quando si aggiunge un blocco a una pagina esistente, guardare come
sono scritti i blocchi vicini.

## 8. Aperto

- Restano i punti della v1.8.63: collegamento fra divisione e tecnico, ed esame
  delle 137 commesse segnalate solo dal gestionale.
- Dopo questa correzione la sincronizzazione va rilanciata in **scrittura**: i
  dati importati finora potrebbero provenire da sole anteprime.
