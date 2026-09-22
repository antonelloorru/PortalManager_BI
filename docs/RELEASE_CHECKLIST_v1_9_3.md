# Release Checklist — PortalManager v1.9.3

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.9.2.

## 1. Integrità dei componenti

| File | Tipo | Verifica |
|---|---|---|
| `VERSION` | dato | n/a — `1.9.3` |
| `header.php` | ROOT, **modificato** | `php -l` OK |
| `assets/pm-tables.css` | **NUOVO** | 17 controlli OK |
| `assets/pm-tables.js` | **NUOVO** | sintassi valida |
| `app/DgbModel.php` | **modificato** — 3 occorrenze | `php -l` OK |
| `app/Version.php` | modificato | `php -l` OK |
| 30 file restanti | invariati da v1.9.2 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.9.3**
- [x] **La cartella `assets\` è nuova**: va creata in installazione, altrimenti i
      file non vengono trovati e le tabelle restano come prima

## 2. I nomi invertiti

- [x] La v1.8.91 correggeva l'**ordinamento**, non la **forma** di costruzione
- [x] `DgbModel` concatenava `first_name` + `second_name` in **3 punti**
- [x] `dgb_operator` tiene il cognome in `second_name`
- [x] Verificato sui dati: `Alessandro Abignente` → **`Abignente Alessandro`**
- [x] `ORDER BY name` segue automaticamente la nuova forma
- [x] Dichiarato che **non c'è un punto unico**: ogni classe costruisce il nome
      per conto proprio, ed è il motivo per cui questo era sfuggito

## 3. Tabelle scorrevoli

- [x] **Agisce sul DOM**, non richiede modifiche alle 15 viste: toccarle una per
      una sarebbe stato 15 occasioni di dimenticarne una
- [x] **`position: sticky`, non un secondo `<table>`**: due tabelle affiancate
      richiedono di sincronizzare le larghezze e si disallineano al primo
      contenuto più largo del previsto
- [x] `z-index` a tre livelli — 3 intestazione, 2 prima colonna, **4 incrocio**:
      senza il 4 la cella in alto a sinistra scompare scorrendo in diagonale
- [x] **`box-shadow` invece di `border`**: il bordo di una cella sticky non viene
      ridisegnato durante lo scorrimento

## 4. Adattamento allo schermo

| Schermo | Altezza |
|---|---|
| ≥1000px | 70vh |
| normale | 62vh |
| ≤700px | 55vh |
| ≤560px | 48vh |

- [x] Altezze in **`vh`**, non pixel: 500px sono metà schermo su un portatile e
      un quarto su un monitor
- [x] Sotto 900px il corpo cala invece di troncare: **su una tabella economica un
      numero troncato è peggio di un numero piccolo**
- [x] Colonna fissa **condizionata** alla larghezza reale, rivalutata al resize
      con ritardo di 150 ms

## 5. La stampa — il rischio principale

- [x] Un contenitore `overflow: auto` **stampa solo la porzione visibile**: 200
      commesse diventerebbero 20 righe **senza alcun segnale**
- [x] `@media print` rimuove il limite, disattiva `sticky`, imposta
      `display: table-header-group` per ripetere l'intestazione
- [x] `page-break-inside: avoid` sulle righe

## 6. Dove non si attiva

- [x] Meno di **8 righe**: l'intestazione non esce mai dalla vista
- [x] Report di stampa (`.nostampa`, `.pm-print`): hanno un impaginato proprio
- [x] Tabelle senza `<thead>`
- [x] Tabelle già avvolte: `closest('.pm-scroll')`

## 7. QA

| Controllo | Esito |
|---|---|
| Casi limite CSS e JS | **17 su 17** |
| `php -l` su tutti i file | OK |
| Migration RUN1/RUN2 | 4 stmt, **err=0**, idempotente |
| **Consolidato completo su dati reali** | **683 stmt, err=0** |
| `;` nei commenti SQL | **0** |

- [x] Il consolidato è stato finalmente rieseguito **per intero** su `pm_real`
      con le 1.092 commesse: era rimasto in sospeso dalla v1.8.98

## 8. Aperto

- **Verifica visiva non eseguita**: non posso aprire il portale in un browser. Il
  comportamento è verificato sui casi limite del codice, non a schermo.
- Se restassero nomi invertiti altrove, serve sapere **in quale schermata**: la
  forma dipende dalla classe che costruisce il nome.
- Restano gli aperti precedenti: viste dei pannelli su `value_total - actual_cost`
  invece di `margin_total`, pagine mancanti, riepiloghi cadenzati.
