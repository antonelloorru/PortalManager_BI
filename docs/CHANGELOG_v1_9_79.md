# PortalManager v1.9.79 — Filtri Smart working / Reperibilità: dati a zero

## Diagnosi (dump produzione 18/09/2026)
- Le viste (`v_cm_it_servizio`, `v_cm_it_giorni_base`, `v_cm_sd_*`) ricavano **modalità** e **fascia oraria**
  dall'attività DGB agganciata al rapportino (`cm_intervention_reports.dgb_activity_id`).
- Il dataset **«Rapporti di intervento»** della sincronizzazione dal gestionale non scriveva
  `dgb_activity_id`: da luglio i rapportini arrivano da lì e restavano scollegati
  (luglio 172, agosto 796, settembre **1.379 su 1.380**; 2.491 in tutto).
- Senza attività la vista li classificava tutti `in sede` e fascia `non rilevata`:
  settembre = 1.387 «in sede», **0 reperibilità, 0 smart working**, 1.356 «non rilevata».
  Filtrando Smart working o Reperibilità non restava alcuna riga → grafici «Ore per modalità / linea di
  servizio / settore / azienda / codice linea» e «Durata e fascia oraria» vuoti.
- Filtri e query della pagina erano corretti (verificati: stesse condizioni restituiscono dati sui mesi collegati).

## Correzione
- **Collegamento** (`app/PmReportLink.php`, nuovo): `dgb_source_id` = id allocazione
  (`dgb_forms_activity_operator.id`, 2.490/2.491 verificati sul codice modulo) → `dgb_activity_id`,
  `dgb_activity_code`; in subordine codice modulo univoco. Idempotente.
- **Migration**: recupero dello storico (stessa logica), indici, copie delle viste marcate scadute.
- **Alla fonte** (`app/SyncDatasets.php`, dataset `rapporti`): la query porta `a.id` / `a.code`, mappati su
  `dgb_activity_id` / `dgb_activity_code`; le righe esistenti si aggiornano al primo sync (upsert su `source_uid`).
- **Rete di sicurezza**: collegamento automatico a fine sincronizzazione pianificata (`app/SyncRunner.php`,
  prima della ricostruzione delle copie) e dopo l'import CSV DGB (`dgb_activities.php`).
- **Relazione IT**: avviso con il numero di moduli del periodo non collegati (modalità/fascia non rilevabili).

## Risultato (settembre 2026, produzione 18/09)
| Modalità | Prima | Dopo |
|---|---|---|
| in sede | 1.387 | 927 |
| da remoto | 1 | 355 |
| presso cliente | 0 | 56 |
| smart working | 0 | 41 |
| reperibilità | 0 | 9 (= `on_call` dei rapportini) |
Fascia: `non rilevata` 1.356 → 0 (in orario 1.199, fuori orario 189).
Filtri Smart working / Reperibilità: tutti i grafici e le tabelle della pagina popolati.

## QA
- Migration RUN1/RUN2 err=0 (scollegati 2.491 → 0 → 0), nessun `;` nei commenti SQL.
- `PmReportLink::relink()` su dump BI: 828 collegati, seconda esecuzione 0.
- `php -l` su tutti i file.
