# Release Notes — PortalManager v1.9.41

Data: 2026-09-07
Allineamento versioni: Software 1.9.41 · Schema 1.9.41 · Upgrade 1.9.41
Sezione: Relazione di Servizio IT (`relazione_servizio_it.php`)

## Bug fix — Riepilogo per Codice Contratto: dettaglio per commessa non visualizzato
Ripristinata la sezione **"Riepilogo per Codice Contratto"** con il **dettaglio per
commessa**, assente dopo il refactor v1.9.40.

Causa del bug storico (v1.9.35): il riepilogo interrogava `dgb_forms_activity` in
INNER JOIN su `dgb_forms_activity_operator`, tabella vuota in produzione → nessuna
riga per commessa pur essendo i rapportini corretti. La sezione è ora costruita sulla
vista canonica `v_cm_sd_moduli` (rapportini reali), che raggruppa correttamente per
contratto e commessa.

Per ciascun **Codice Contratto** viene mostrata una riga di sottototale e, sotto, il
dettaglio per **commessa**: area tecnologica, interventi, giorni-uomo, ore, ore extra
e produzione teorica a listino (`cm_rate_band_rates.cost_type='Cliente'`).

## Contenuto pacchetto
```
VERSION                              1.9.41
relazione_servizio_it.php  (ROOT)    + sezione Riepilogo per Codice Contratto (dettaglio commessa)
sql/migration_v1_9_41.sql            ri-assert v_rsi_report_fascia + bump + registrazione
sql/upgrade_1_9_39_to_1_9_41.sql     consolidato ultime 2 versioni -> 1.9.41
docs/                                changelog, manuali, deployment, technical design
```

## QA
- `php -l` OK.
- Query riepilogo contratto→commessa verificata su stub: righe per commessa presenti
  (prima 0 per l'INNER JOIN sulla tabella vuota), sottototali e produzione teorica corretti.
- SQL migration/consolidato: RUN1/RUN2 err=0; `;` nei commenti = 0.
- Post-upgrade: versioni = 1.9.41.
