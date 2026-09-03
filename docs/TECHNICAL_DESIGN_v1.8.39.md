# PortalManager — Technical Design v1.8.39
## Dati economici per anno di competenza

### 1. Obiettivo
I dati economici del controllo di gestione HR (input per-dipendente e parametri di
riferimento globali) hanno validità annuale. La release introduce la dimensione
**anno di competenza** (esercizio), la selezione delle viste per anno, il confronto
tra annualità e un modello di importazione massiva per anno. I dati preesistenti
sono attribuiti all'esercizio **2025**.

### 2. Modello dati (schema ER)

```
                +-------------------------+
                |   hr_economic_years     |   catalogo esercizi
                |-------------------------|
                | year (PK)               |
                | label                   |
                | is_current  (0/1)       |
                | is_locked   (0/1)       |
                | note                    |
                +-----------+-------------+
                            | 1
                            |            anno di competenza
              +-------------+--------------------------------+
              | N                                            | N
   +----------v-------------------+          +---------------v---------------+
   |   hr_employee_economics      |          |     hr_reference_values       |
   |------------------------------|          |-------------------------------|
   | id (PK)                      |          | id (PK)                       |
   | employee_id  --> employees.id|          | ref_key                       |
   | year                         |          | year                          |
   | ral, premio_concordato, ...  |          | ref_value, decimals, label    |
   | 16 colonne di input econ.    |          | UNIQUE(ref_key, year)         |
   | UNIQUE(employee_id, year)    |          +---------------+---------------+
   +----------+-------------------+                          |
              |                                              | storico
              | mirror (solo anno corrente)      +----------v-----------+
   +----------v-------------------+               | hr_reference_history |
   |         employees            |               | + colonna year       |
   | colonne economiche legacy    |               +----------------------+
   | (compat scheda anagrafica)   |
   +------------------------------+

        hr_formulas / hr_formula_history : formule di calcolo (globali, invariate)
        finance_view_prefs               : preferenze colonna per utente (invariate)
```

Precisioni colonne `hr_employee_economics` (allineate a `employees`):
`ral,premio_concordato,fuori_sede_amount,valore_tabp` DECIMAL(10,2);
`km_concordati,km_effettivi` DECIMAL(8,2); `moltiplicatore_fc` DECIMAL(12,5);
`moltiplicatore_fte,val_km` DECIMAL(10,4); `overhead_aziendale` DECIMAL(9,4);
`incentivazione_extra,valore_medio_anno_auto` DECIMAL(12,2);
`qt_trasferte_annue,qt_buoni_pasto` DECIMAL(10,2);
`classificazione_finanziaria` ENUM('Diretto','Indiretto'); `fuori_sede` TINYINT(1).
`hr_reference_values.ref_value` DECIMAL(18,6).

### 3. Logiche di calcolo (`app/CostModel.php`)
Il modello di costo è **anno-aware** e retrocompatibile:

- `currentYear()` — esercizio corrente: `hr_economic_years.is_current` → max anno
  catalogato → max anno con dati → anno solare (fallback 2025).
- `years()` — elenco esercizi `[year => label]`, garantendo l'anno corrente.
- `resolveYear($y)` — valida un anno rispetto al catalogo; se assente ritorna l'anno corrente.
- `refs($year)` — valori di riferimento globali dell'anno: query
  `WHERE year = (SELECT MAX(year) FROM hr_reference_values WHERE year <= :year)`
  (caduta all'anno precedente più vicino) → `app_settings` → default. Cache per-anno.
- `economics($empId, $year)` — riga di input da `hr_employee_economics` o `null`.
- `compute($e, $year)` — invariato nelle formule (tabella `hr_formulas`); usa i
  riferimenti dell'anno indicato. `$e` è una riga di input (da `employees` o da
  `hr_employee_economics`, stessi nomi colonna): la parità di calcolo tra le due
  sorgenti è garantita dal backfill.

Formule (invariate): FullCost = RAL×MoltFC; TotAAxTA+BP = (Buoni+Trasferte)×ValoreTABP;
Rimborso KM = Km×Val.KM; TotalePreOverHead = FullCost+TotAAxTA+BP+RimborsoKM+Incentivo+Auto;
TotCostoTab = TotalePreOverHead×(1+OverHead); CostoNoAuto = FullCost+TotAAxTA+BP+Incentivo;
ValoreFTE = CostoNoAuto×MoltFTE; TotaleFTE+CA = TotCostoTab+ValoreFTE.

### 4. Relazioni tra viste
- **finance_overview.php** — quadro dipendenti per esercizio. Selettore anno; le
  colonne economiche sono risolte via `LEFT JOIN hr_employee_economics ee ON
  ee.employee_id=e.id AND ee.year=:year`. Per l'anno corrente il valore è
  `COALESCE(ee.col, e.col)` (fallback alla colonna legacy), per gli altri anni `ee.col`.
  I valori derivati arrivano da `CostModel::compute($srcRow, $year)`. Export XLSX/CSV
  con anno nel nome file. Deep-link a `employee_compensation` con `year`.
- **employee_compensation.php** — scheda riservata HR per esercizio. Legge/scrive
  `hr_employee_economics` (UPSERT su `employee_id`+`year`); per l'anno corrente
  rispecchia in `employees`. Esercizio bloccato ⇒ sola lettura.
- **finance_compare.php** — confronto anno A vs anno B su una metrica, per dipendente
  e in aggregato, con delta assoluto e %. `LEFT JOIN` doppio (anno A e anno B),
  metrica calcolata da `CostModel`. Export XLSX.
- **hr_economic_years.php** — CRUD esercizi, imposta corrente, blocco, clonazione.
- **import_economics_xlsx.php** — template XLSX + import massivo per anno (UPSERT).
- **hr_reference_values.php** — invariata; opera sui riferimenti (l'era per-anno è
  trasparente: la pagina agisce sull'anno di default corrente tramite il default di colonna).

### 5. Sicurezza e permessi
Nuove pagine con permesso dedicato, assegnate di default a Super Admin (id 1),
HR Director (id 2) e Responsabile Finanziario (id 10). Le colonne economiche in
Finance restano dietro il permesso riservato HR. Tutti i POST seguono il pattern PRG
con verifica CSRF; scritture tracciate con `write_log`.

### 6. Compatibilità
- Nessuna colonna rimossa: le colonne economiche di `employees` restano come mirror
  dell'anno corrente per la scheda anagrafica e le pagine che le leggono direttamente.
- Migrazione idempotente (`ADD COLUMN IF NOT EXISTS`, `DROP INDEX IF EXISTS`,
  `ADD UNIQUE KEY IF NOT EXISTS`, `INSERT IGNORE`, UPSERT), ri-eseguibile senza effetti.


### 7. Carico risorse — granularità giornaliera (v1.8.39)
`app/Workload.php` aggiunge la granularità giornaliera senza toccare le viste mensili:
`daysOfMonth($ym)`, `isWorkingDay($date)`, `dailyCapacity($ym)` (ore/giorno feriale, 0 nei
weekend), `dailyMatrix($ym,$f)` (risorsa × giorno dai `cm_intervention_reports`, stessi filtri
di `matrix()`) e `dailyChartSeries()`. In `workload_overview.php` il selettore *Mese di dettaglio*
(`dm=YYYY-MM`, default ultimo mese del periodo) alimenta un grafico SVG server-side con bande
weekend e linea di capacità giornaliera. Nessuna modifica di schema.
