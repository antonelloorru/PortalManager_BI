# Deployment — v1.9.79 (richiede v1.9.78)
1. Copiare in root: `it_service.php`, `dgb_activities.php`.
2. Copiare in `app/`: `PmReportLink.php` (nuovo), `SyncDatasets.php`, `SyncRunner.php`, `ItServiceModel.php`.
3. Eseguire `sql/migration_v1_9_79.sql` (idempotente; collega lo storico dei rapportini).
4. Aggiornare le copie delle viste: Sistema → Prestazioni → aggiorna copie (oppure attendere la ricostruzione
   automatica, richiesta alla prima apertura delle pagine).
5. Verifica — Relazione di Servizio IT, settembre 2026:
   - filtro Modalità = Smart working, poi Reperibilità: KPI e grafici popolati;
   - «Durata e fascia oraria»: nessuna «Non rilevata» residua;
   - nessun avviso «moduli di intervento non collegati» (oppure numero residuo esiguo).
6. Alla prossima sincronizzazione: log con la riga «Rapportini collegati alle attività DGB».
