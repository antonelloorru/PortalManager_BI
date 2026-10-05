# Manuale Amministratore v1.10.04 — Ore DGB

- Fasce ordinarie: `app_settings.pm_orario_fasce` (es. `09:00-13:00,14:00-18:00`). Valgono per Relazione di Servizio IT, Service Desk e Attività & Rendicontazione DGB.
- Reperibilità: flag `during_availability` delle allocazioni DGB, riportato in `on_call` dei moduli sincronizzati.
- Profilo «turni» degli incaricati: resta come filtro e classificazione anagrafica; non rende più ordinarie tutte le ore.
- Dopo l'aggiornamento eseguire «Sincronizza moduli» solo se si vuole riallineare: la migration ha già completato `end_at`.
- Controllo: `php tools/verify_v1_10_04.php --db=<database>` (sola lettura) confronta le classi DGB col codice della Relazione IT.
