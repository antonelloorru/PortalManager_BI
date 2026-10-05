# CHANGELOG — v1.9.98
Attività & Rendicontazione DGB — filtri della Relazione di Servizio IT + calcolo sul modello DGB.

## Filtri (replica Relazione di Servizio IT)
- Gruppi identici: Contratto e stato commessa · Periodo e ricerca · Servizio · Erogazione; i campi propri DogoBit in «Attività DGB».
- Nuovi: Stato commessa (Aperta/Chiusa/Sospesa/Non chiusa), Cliente (testo), Linea di servizio, Codice linea, Settore tecnologico, Azienda esecutrice, Natura (ricavo/interne), Sede di riferimento, Fascia oraria, Durata, Raggruppa per.
- Modalità con i 5 valori della Relazione IT (in sede, da remoto, presso cliente, smart working, reperibilità); link v1.9.97 (`sede`/`remoto`/`smart`) ancora validi.
- «Cerca ovunque»: codice attività, ticket, commessa (codice/descrizione/cliente grezzo), cliente.
- Tutti i filtri in un unico pannello server-side: KPI, grafici, quadro del periodo, dettaglio, tabella, export.

## Logica di calcolo (dominio DGB)
- Nuova sezione «Dettaglio — {dimensioni}» su allocazioni incaricato × attività: attività, giornate-uomo, ore consuntivate, ordinarie, straordinario, reperibilità, viaggio, costo, ricavo, margine, conteggi per modalità; riga Totale.
- Export XLSX «Dettaglio aggregato» (completo, con foglio Filtri).
- Foglio Filtri degli export esteso ai nuovi campi.
