# PortalManager v1.9.80 — Report Certificazioni: filtri come Relazione di Servizio IT

## UI
- Pannello `<details class="pm-panel">` a gruppi, badge con il numero di filtri attivi, conteggio nel titolo
  («N certificazioni · N collaboratori»), aperto se un filtro è attivo — stesso template della Relazione IT.
- Select multiple con ricerca integrata (`pm-ms`, pm-multiselect v2) al posto delle liste di checkbox.
- Riepilogo sotto il pannello: certificazioni, attive, in scadenza, scadute (cliccabili = filtro), collaboratori, con PDF.
- Azioni: Applica, Azzera, Stampa. Export CSV/XLSX/PDF/DOCX/ODT (ListFilter) invariato sui dati filtrati.

## Parametri filtrabili (da 3 a 21)
| Gruppo | Filtri | Parametro |
|---|---|---|
| Ricerca e date | testo libero (certificazione, codice, n. certificato, note, collaboratore) | `q` |
| | conseguita dal/al, scadenza dal/al | `iss_from` `iss_to` `exp_from` `exp_to` |
| | scade entro 30/60/90/180/365 giorni | `exp_in` |
| Certificazione | brand, partnership del brand, tecnologia, categoria, livello, certificazione | `f_br` `f_plv` `f_tec` `f_cat` `f_lvl` `f_cert` |
| Collaboratore | collaboratore, stato, azienda, sede, reparto, mansione, unità tecnica | `f_us` `f_empst` `f_az` `f_sede` `f_rep` `f_job` `f_unit` |
| Stato ed evidenze | stato certificazione, perpetua/con scadenza, PDF, Credly, codice certificato | `f_st` `f_perp` `f_pdf` `f_credly` `f_code` |
- Multi-valore in formato array o CSV (`PmFilter`), parametri preparati; nomi storici `f_br` / `f_us` / `f_st` invariati.
- Opzioni: solo valori presenti nelle certificazioni; collaboratori anche non attivi (stato tra parentesi).
- Ruolo Dipendente: invariato il vincolo sulle proprie certificazioni, pannello senza gruppo Collaboratore.

## Correzione
- **Stato certificazione calcolato dalla scadenza** (soglia `notify_days_1`, come `cert_status_from_date`) per filtro,
  riepilogo e badge: lo stato memorizzato si aggiorna solo al salvataggio (dump 18/09: 98 «scadute» memorizzate,
  101 effettive).
- Credly «presente» = stesso criterio del link in tabella (badge con codice UUID o profilo del collaboratore).

## QA (dump 18/09: 556 certificazioni, 49 collaboratori)
- 10 combinazioni di filtri: nessun errore; scadute 101 = conteggio diretto; `x' OR 1=1--` → 0 righe.
- Ruolo Dipendente: solo le proprie (65), nessun filtro Collaboratore.
- Migration RUN1/RUN2 err=0; `php -l` ok.
