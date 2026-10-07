# MANUALE AMMINISTRATORE — v1.10.13

- Report e ZIP della Relazione di Servizio IT richiedono il permesso **export** su it_service.php.
- Tempo di generazione: la relazione generale PDF ~1–6 s secondo il periodo; lo ZIP per incaricato scala con il numero di incaricati (es. 80 incaricati in XLSX ≈ 1 minuto).
- Ogni report registra in event_log: formato, generale / personale / ZIP, periodo.
- Il parametro `target` (ore ordinarie mensili) vale anche per i report generati.
