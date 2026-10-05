# CHANGELOG — v1.10.00
Progetti PRJ — fase 3 di 6: motore di calcolo.

## Funzionalità
- `app/PrjCalc.php` — motore puro (nessun accesso al DB), formule del Technical Design §5:
  - carico ticket per servizio: ticket, ore, FTE da ticket, FTE con uplift, FTE allocati, minimo, scostamento; ore medie per tipo con override per servizio;
  - costo per profilo: RAL min/ideale/media, indice zona e nearshore, oneri, indennità H24 solo sui profili H24;
  - costi strutturali: dotazione, affitto, energia; ufficio o remoto (FTE marcati remoti e nearshore); costi di sede;
  - overhead fissi e per FTE;
  - totali scenario: FTE, costo personale, oneri, indennità, costo aziendale personale e totale, canone medio e netto, % canone, margine, valore punto di ribasso, ribasso massimo a pareggio, FTE finanziabili, costo medio per FTE e giornaliero, peso strutturali, valore unitario ticket, costo sulla durata;
  - andamento per anno di contratto, con avvio dei servizi dall'anno previsto;
  - conguaglio banda volumi ±20%;
  - simulatore penali (per giorno, risorsa, blocco di ticket per priorità, minuto, punto %, % sforamento, una tantum, formula personalizzata);
  - punteggio tecnico (C, D, E, F, G, H, discrezionali) ed economico (PE con K1, K2, w, n1, n2).
- `app/PrjRepo.php`:
  - lettura as-of delle tabelle versionate, con parametri globali sovrascrivibili per progetto;
  - oneri di default da `hr_mult_fc` dell'anno se il parametro manca;
  - input del motore per progetto, scenario e data;
  - calc run immutabili con hash SHA-256, snapshot JSON e versione software/schema; risultati per totale, servizio, profilo, anno e zona; confronto tra run (delta e %);
  - scrittura versionata con EntityChangeLog;
  - codice PRJ-AAAA-NNNN atomico.

## QA
- `tools/verify_v1_10_00.php`: 43 OK, 0 KO. Tutti i valori del §9 della specifica sono entro ±1 k€, più coerenza interna, versioning as-of, calc run, RESTRICT sui run, codice PRJ.
- Migration RUN1/RUN2 err=0 su Dump 19.80 + v1.9.99.
- `php -l` su tutti i file.
