# Release Checklist — PortalManager v1.9.15

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.9.14.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.9.15` |
| `service_desk.php` | ROOT, **modificato** | OK |
| `it_service.php` | ROOT, **modificato** | OK |
| `app/SdModel.php` | **+ 5 metodi** | OK |
| `app/ItServiceModel.php` | **+ 3 metodi** | OK |
| `app/Version.php` | modificato | OK |
| 28 file restanti | invariati da v1.9.14 | OK |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.9.15**

## 2. Le tariffe ricostruite

- [x] Il template dava **ore e valori, non le tariffe**
- [x] Nove divisioni indipendenti convergono su **quattro valori al centesimo**:
      100,00 / 87,50 / 81,25 / 120,00
- [x] **`origine` in tabella**: `dedotta da template`. È il correttivo all'errore
      ripetuto tre volte in questa serie — dedurre e presentare il risultato come
      un dato

## 3. Scaglioni, non pacchetti

- [x] Tre mezze giornate = **1.225,00** nel template; a pacchetto sarebbero
      1.050,00 — **scarto del 14%**
- [x] Conferma decisiva: «Fascia C 5 ore» = 437,50, che un pacchetto di mezza
      giornata **non può produrre**
- [x] La soglia sta fra 4 e 5: «2 ore» a 100,00 €/h, «5 ore» a 87,50

## 4. Riproduzione del template

| Descrizione | Template | Portale |
|---|---|---|
| Fascia C (Fascia oraria) | 9 / 19,5 / 1.950,00 | **identico** |
| Fascia C (Mezza giornata) | 3 / 14 / 1.225,00 | **identico** |
| Fascia C Giornata | 8 / 64 / 5.200,00 | **identico** |
| Fascia D (Fascia oraria) | 3 / 3,5 / 420,00 | **identico** |
| **TOTALE** | **101 h / 8.795,00** | **identico** |

- [x] Anche la riga anomala «5 ore» → **437,50**
- [x] Layout dell'export: **7 righe attese, 0 mancanti**, ordine compreso

## 5. Le due sezioni condividono le viste

- [x] Service Desk **8.795,00** = Relazione IT **8.795,00**
- [x] Duplicare avrebbe dato due definizioni degli scaglioni: alla prima
      divergenza nessuno saprebbe quale credere
- [x] Cambia il perimetro dei filtri, non il calcolo

## 6. Un'incoerenza dichiarata

- [x] Modulo senza attività DGB: nel **calcolo costi** ricade su **fascia C**,
      nella **Relazione IT** produce «non rilevata»
- [x] Qui non è possibile una terza categoria: il calcolo deve produrre un valore
      e «non rilevata» non ha tariffa
- [x] Fascia C e non D: gonfiare la tariffa più alta su casi ignoti è peggio che
      sgonfiarla

## 7. QA

| Verifica | Esito |
|---|---|
| Righe del template | **4 su 4 identiche** |
| Quadratura ore | 101,00 = 101,00 |
| Quadratura valore | 8.795,00 = 8.795,00 |
| Ordinario + extra = totale | 8.795,00 |
| Service Desk = Relazione IT | **8.795,00 = 8.795,00** |
| Scaglioni per durata | 7 combinazioni |
| Layout export | 7 righe, **0 mancanti** |
| Metodi inesistenti | **0** |
| `<div>` in stampa | **83 = 83** |
| `if`/`endif` in it_service | **6 = 6** |
| Migration RUN1/RUN2/RUN3 | 12 stmt, **err=0** |
| Coda consolidato RUN1/RUN2 | 10 stmt, **err=0** |
| `;` nei commenti SQL | **0** |

## 8. Difetti intercettati in collaudo

- [x] `cm_projects.name` senza valore predefinito e vincolo su `project_code`:
      le commesse del template esistono già con linea diversa — collaudo spostato
      su commesse reali delle tre linee
- [x] Taglio del consolidato a metà istruzione: riga di partenza corretta
- [x] `if` senza `endif` in `it_service.php`: rilevato da `php -l`

## 9. Aperto

- **Due combinazioni senza tariffa**: fascia D mezza giornata e giornata non
  comparivano nell'esempio. NULL = da stabilire.
- **Le tariffe sono dedotte**: se le condizioni contrattuali reali differiscono,
  vanno corrette e marcate `dichiarata`.
- **I moduli sono vuoti nel dump**: il collaudo usa 23 moduli costruiti che
  riproducono i casi del template, poi rimossi.
- Restano gli aperti precedenti: risincronizzazione dopo la v1.9.12, giorni vuoti
  sull'asse giornaliero, `workload_overview` e `dgb_activities` non uniformati.
