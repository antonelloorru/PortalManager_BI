# Release Checklist — PortalManager v1.8.53

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.52.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.53` |
| `dgb_activities.php` | ROOT, modificato | OK |
| `app/DgbModel.php` | **modificato** (regola oraria) | OK |
| `app/Version.php` | modificato | OK |
| altri 5 ROOT + 7 in `app/` | invariati da v1.8.52 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.53**
- [x] Nessuna riga di dati modificata o eliminata: la migration aggiunge
      parametri e tre viste

## 2. Regola implementata

- [x] Ordinario: lun–ven 09:00–13:00 e 14:00–18:00 (8 h/giorno)
- [x] Reperibilità: sabato, domenica, 18:01–08:59, pausa pranzo
- [x] Eccezione turnisti (`schedule_type = 'turni'`)
- [x] Parametri in `app_settings`, non cablati come unica fonte

## 3. QA — casi limite della formula

Dieci casi verificati eseguendo l'espressione sul database, **0 non conformi**.

| Intervento | Atteso | Ottenuto |
|---|---|---|
| lun 09:00–13:00 | 1,000 | 1,000 |
| lun 14:00–18:00 | 1,000 | 1,000 |
| lun 09:00–18:00 (attraversa la pausa) | 0,889 | 0,889 |
| lun 13:00–14:00 (solo pausa) | 0,000 | 0,000 |
| lun 18:30–20:30 | 0,000 | 0,000 |
| lun 07:00–09:00 | 0,000 | 0,000 |
| lun 08:00–10:00 (a cavallo apertura) | 0,500 | 0,500 |
| lun 17:00–19:00 (a cavallo chiusura) | 0,500 | 0,500 |
| sabato 10:00–12:00 | 0,000 | 0,000 |
| domenica 10:00–12:00 | 0,000 | 0,000 |

- [x] Turnista: frazione forzata a 1,0, verificato

## 4. QA — quadratura sui dati reali

67.786 allocazioni, dump `demo_portalmanager`.

| Verifica | Esito |
|---|---|
| Ore consuntivate | 328.629,00 — **invariate** |
| Ore ordinarie | 284.332,85 |
| Ore reperibilità | 44.296,15 |
| **Scarto** (ord + rep − consuntivate) | **0,00** |
| Percentuale di reperibilità | 13,48% |

- [x] Primo tentativo con entrambe le componenti come prodotto: scarto **5,08 h**
      da arrotondamento. Corretto derivando la reperibilità per **differenza**,
      così la quadratura è esatta per costruzione

## 5. QA — coerenza fra le due definizioni

L'espressione esiste nella vista SQL e in `DgbModel::FRAC_ORD`. Confronto sui
totali di giugno 2023:

| Fonte | Ordinarie | Reperibilità | Totale |
|---|---|---|---|
| modello | 4.402,51 | 640,49 | 5.043,00 |
| vista | 4.402,08 | 640,92 | 5.043,00 |

- [x] Scarto 0,43 h su 4.402 (0,01%), imputabile all'ordine di arrotondamento:
      la vista arrotonda per riga, il modello sull'aggregato
- [x] Se le definizioni divergessero, lo scarto crescerebbe di ordini di grandezza
- [x] Il confronto è parte del collaudo permanente

## 6. QA — impatto della riclassificazione

| Classe | Allocazioni | Ore | Ordinarie | Reperibilità |
|---|---|---|---|---|
| misto | 35.829 | 262.278,00 | 226.577,35 | 35.700,65 |
| ordinario | 29.229 | 57.755,50 | 57.755,50 | 0,00 |
| weekend | 1.194 | 4.434,50 | 0,00 | 4.434,50 |
| fuori orario | 1.534 | 4.161,00 | 0,00 | 4.161,00 |

- [x] Reperibilità dichiarata 5.299 h → effettiva 44.296 h
- [x] Totale consuntivato invariato: 328.629,00 = 328.629,00

## 7. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_demo` | 8 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_demo` | 8 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_demo` | 8 stmt, **err=0** |
| Migration RUN4 | tokenizer reale | `pm_demo` | 8 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c53` fresco | 386 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c53` | 386 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c53` | 386 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Un `;` in un commento aveva fatto fallire lo splitter naive al primo
      tentativo: individuato e corretto
- [x] Conteggio statement consolidato: **380 → 386**

## 8. Scelte dichiarate

- [x] **Le ore non sono ricalcolate dagli orari**: durata cronologica media 5,50 h
      contro 4,84 h consuntivate; usare la durata gonfierebbe del 14%
- [x] **Interventi a cavallo della mezzanotte** (0,58%, 406 su 70.238)
      classificati sul giorno e orario di inizio: spezzarli richiederebbe una
      tabella calendario per un guadagno inferiore ad altre incertezze del dato
- [x] **Duplicazione dell'espressione** fra vista e modello accettata per non
      riscrivere la catena dei filtri, e presidiata dal confronto in collaudo
- [x] **Parametri in configurazione** ma orari cablati anche nell'espressione SQL:
      renderli dinamici significherebbe costruire per concatenazione una query
      eseguita su 70.000 righe a ogni caricamento. Un cambio di orario richiede
      una release che allinei le tre definizioni — documentato nel deployment

## 9. Aperto

- **Nessun turnista è censito**: i 146 profili sono tutti "ordinario". Finché non
  vengono classificati, le ore dei turnisti fuori fascia risultano in
  reperibilità. La pagina ha già la funzione di auto-classifica; il deployment
  indica il passo come attività successiva all'aggiornamento.
- La regola presuppone che l'orario di inizio e fine dell'intervento sia
  attendibile. Lo è per il 98,8% delle righe (intervallo valido); per le altre la
  classificazione ricade sull'istante di inizio.
