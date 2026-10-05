# TECHNICAL DESIGN — v1.9.94

## A. Filtri (SQL)
```sql
-- DirModel::where(), alias c = v_cm_dir_commessa
AND (c.end_date   IS NULL OR c.end_date   >= :from)
AND (c.start_date IS NULL OR c.start_date <= :to)
-- andamento
AND anno_mese BETWEEN DATE_FORMAT(:from,'%Y-%m') AND DATE_FORMAT(:to,'%Y-%m')
```

## B. Formattazione (PHP, dir_report.php)
`$eur` (0 decimali + « €»), `$eur2` (2 decimali + « €»), `$hrs` (« h»), `$gg` (« gg»), `$fidoBadge($row)`.
Fido: `DirModel::FIDO = (COALESCE(pf.credit_on_value,0) <> 0 OR COALESCE(pf.credit_on_costs,0) <> 0)` con `pf = cm_projects`.

## C. Pro-rata (PHP app/ProRata.php · SQL equivalente)
```
s  = max(start_date, op_date)           e = end_date
N  = (Y(e)-Y(s))*12 + M(e)-M(s) + 1     -- SQL: PERIOD_DIFF(DATE_FORMAT(e,'%Y%m'), DATE_FORMAT(s,'%Y%m')) + 1
q  = importo / N
per anno y: mesi_y = |[s,e] ∩ anno y ∩ [from,to]| (in mesi)   valore_y = round(q × mesi_y, 2)
residuo = importo − Σ valore_y (durata intera) → sommato all'ultimo anno
```
Sorgente:
```sql
SELECT c.*, o.op_type_code, o.op_date, o.order_code, o.revenue
  FROM v_cm_dir_commessa c JOIN cm_projects pf ON pf.id = c.commessa_id
  LEFT JOIN cm_project_operations o ON o.project_id = c.commessa_id AND o.op_type_code IN ('COR','COV') AND o.revenue <> 0
 WHERE <filtri> AND c.ha_ricavo = 1
```
Output `competenza()`: `periodo`, `anni[y] = {valore, commesse}`, `commesse[] = {…, importo, anni[y]={valore,mesi}, valore_periodo, ordini[]}`, `totale`.

## ER
cm_projects 1—N cm_project_operations (project_id; tipi da cm_operation_types: COR, COV = gruppo REC). Nessuna modifica di schema;
indice `cm_project_operations(project_id, op_type_code)`.
