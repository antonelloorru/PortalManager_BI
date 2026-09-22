# Release Checklist — PortalManager v1.9.17

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.9.16.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.9.17` |
| `service_desk.php` | ROOT, **modificato** | OK |
| `it_service.php` | ROOT, **modificato** | OK |
| `app/it_service_print.php` | **modificato** | OK |
| `app/Version.php` | modificato | OK |
| 29 file restanti | invariati da v1.9.16 | OK |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.9.17**

## 2. Le due lacune colmate

- [x] **Service Desk**: costi solo nel generale → ora anche nel **personale**
- [x] **Relazione IT**: nessun report personale → ora riconosciuto, con costi
- [x] I costi sul singolo tecnico rispondono a una domanda diversa dal totale di
      squadra, e restava senza risposta stampabile

## 3. Il personale dai filtri, non da un parametro

- [x] `isPers = (incaricati selezionati === 1)`
- [x] Un flag separato avrebbe permesso il report personale **con tre incaricati
      selezionati**: titolo con un nome, dati di tre
- [x] Verificato su **3 casi**: 0 → generale, 1 → personale, 2 → generale
- [x] Il pulsante cambia etichetta: uno che dice «generale» e produce una scheda
      personale fa dubitare dei dati

## 4. Difetto della v1.9.16 trovato ora

- [x] `$mo->costiQuadro()` in due file, ma il modello si chiama **`$it`**
- [x] `php -l` **non lo vede**: variabile non definita è sintatticamente valida
- [x] Si manifesta solo eseguendo quel ramo
- [x] Controllo aggiunto: variabili usate con `->` contro quelle assegnate con
      `new`. Esito: **0 sospette su 3 file**

## 5. QA sui dati reali

| Verifica | Esito |
|---|---|
| Generale | 25 interventi, 110,00 h, **9.620,00 €** |
| Chiarini | 23 / 101,00 / 8.795,00 |
| Mancini | 2 / 9,00 / 825,00 |
| **Somma personali = generale** | **9.620,00 = 9.620,00** |
| SD generale = IT generale | **identici** |
| SD personale = IT personale | **identici** |
| Riepilogo personale | 4 righe in entrambe |
| `<div>` in it_service_print | **24 = 24** |
| `if`/`endif` | **7 = 7** |
| Migration RUN1/RUN2 | 4 stmt, **err=0** |
| **Consolidato completo** | **746 stmt, err=0** sui dati reali |
| `;` nei commenti SQL | **0** (uno intercettato) |

## 6. Aperto

- **Verifica a schermo non eseguita**: il comportamento è verificato sui dati e
  sul codice, non aprendo il portale.
- **`fascia_origine` da verificare sui dati reali**: se «dedotta da orario»
  prevale, le fasce sono in gran parte supposte e le stampe ereditano
  quell'incertezza.
- Restano gli aperti precedenti: risincronizzazione dopo la v1.9.12, valorizzazione
  a costo (`CEH`) non implementata, giorni vuoti sull'asse giornaliero,
  `workload_overview` e `dgb_activities` non uniformati.
