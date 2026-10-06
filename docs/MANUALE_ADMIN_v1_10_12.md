# MANUALE AMMINISTRATORE — v1.10.12

## Permessi
I report (generale, per tecnico, ZIP) compaiono a chi ha **export** sulla pagina (Gestione permessi: service_desk.php, service_soc.php, dgb_activities.php).
Gli export dati preesistenti non cambiano.

## Requisiti
Estensioni PHP `zip`, `mbstring`, `zlib` (standard XAMPP). Nessuna libreria esterna. Per gli ZIP con molte risorse il tempo di generazione è proporzionale al numero di report (es. DGB: ~2,5 s per incaricato).

## Event log
Ogni report registra in event_log: pagina, formato, generale / tecnico / ZIP e periodo.
