# Deployment — v1.9.77 (richiede v1.9.76)
1. Copiare in root: `it_service.php`; in `app/`: `ItServiceModel.php`, `it_service_print.php`.
2. Eseguire `sql/migration_v1_9_77.sql` (idempotente, nessun delta di schema).
3. Verifica — Relazione di Servizio IT:
   - pannello Filtri → gruppo «Contratto»: la select «Codice Contratto / PM Project» ha la
     barra di ricerca; selezionare una commessa e applicare;
   - KPI, grafici (mensile e giornaliero), barre per dimensione, tabella aggregata, costi,
     giorni per operatore, Riepilogo per contratto e Dettaglio per commessa mostrano solo
     quella commessa/contratto;
   - aprire un contratto nel Dettaglio: righe coerenti con il filtro;
   - Stampa, DOCX: riga «Filtri applicati» con il contratto; XLSX: foglio «Filtri».
4. Rollback: ripristinare i tre file di v1.9.76 (nessuna modifica al DB da annullare).
