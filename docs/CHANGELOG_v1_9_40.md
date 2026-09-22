# Release Notes — PortalManager v1.9.40

Data: 2026-09-07
Allineamento versioni: Software 1.9.40 · Schema 1.9.40 · Upgrade 1.9.40
Sezione: Relazione di Servizio IT (`relazione_servizio_it.php`)

## Refactoring: cruscotto performance per persona (target Direttore IT)
Sostituite le tre sezioni precedenti con un'unica vista raggruppata PER PERSONA e
quattro metriche:

1. **Giorni lavorati su commesse attive** nel periodo — `COUNT(DISTINCT giorno)` sulle
   sole commesse in stato attivo.
2. **Conteggio per Fascia** professionale, con focus su **C** e **D** (colonne dedicate
   + tabella di dettaglio A–F / N/D con interventi e ore).
3. **Area Tecnologica** dai rapportini — linea/modello del contratto (`v_cm_sd_moduli`).
4. **Produzione attiva teorica** — `ore × tariffa di listino della fascia`
   (`cm_rate_band_rates.cost_type='Cliente'`, regime `Ordinario`), sulle sole commesse attive.

## Fix dati (regressione produzione)
La versione precedente interrogava `dgb_forms_activity` in **INNER JOIN** su
`dgb_forms_activity_operator`, tabella **vuota** in produzione → pagina a **0 righe**.
Il refactor legge dalla vista canonica **`v_cm_sd_moduli`** (attività dirette +
`_operator` in LEFT JOIN, ore da `cm_intervention_reports.quantity_hours`): popolata
anche con la tabella operatori vuota.

## Fascia professionale a tre fonti (`v_rsi_report_fascia`)
Risoluzione in cascata con origine tracciata: `report` (band del rapportino) →
`alias` (`cm_alias_band` su `band_raw`, per commessa o globale) → `catalogo`
(fascia unica di listino della commessa). Nessuna fonte risolve ⇒ `N/D` (non inventata).

## Filtri ed export
- Filtri: periodo, **Tecnici**, **Contratti** (liste dai moduli del periodo). I filtri
  per cliente/regime della versione precedente, legati alle query DGB dirette, sono
  stati rimossi perché non esposti dalla vista SD.
- Export **CSV** (`?export=csv`) e vista **Stampa** (`?print=1`), coerenti coi filtri.

## Contenuto pacchetto
```
VERSION                              1.9.40
relazione_servizio_it.php  (ROOT)    cruscotto performance per persona (4 metriche)
sql/migration_v1_9_40.sql            crea/riallinea v_rsi_report_fascia + bump + registrazione
sql/upgrade_1_9_38_to_1_9_40.sql     consolidato ultime 2 versioni -> 1.9.40
docs/                                changelog, manuali, deployment, technical design
```

## QA
- `php -l` OK.
- Query per-persona e per-fascia verificate su scenari mirati (fasce risolte da tutte e
  tre le fonti, non risolta, commesse attive/chiuse/sospese): metriche corrette.
- SQL migration/consolidato: RUN1/RUN2 err=0; `;` nei commenti = 0.
- Post-upgrade: `app_version/schema_version/release_label` = 1.9.40.

## Assunzioni da confermare
- **Commessa attiva = stato `APERTA`** (valori reali: APERTA/CHIUSA/SOSPESA). `SOSPESA`
  esclusa. Modificabile in un unico punto: costante `RSI_STATI_ATTIVI` in testa alla pagina.
- **Produzione teorica** su regime `Ordinario` (la reperibilità non è distinguibile con la
  tabella operatori vuota).
