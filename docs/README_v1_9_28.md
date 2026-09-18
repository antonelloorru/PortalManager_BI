# PortalManager v1.9.28 — Relazione Servizi IT

## Cosa contiene la release
1. **Fix bug**: nel riepilogo "Giorni lavorati per persona" il dato
   giorni-uomo era `SUM(ore) / 8`, che nasconde le giornate a orario ridotto
   (10 giornate x 4h risultavano come 5 giornate x 8h). Ora è
   `COUNT(DISTINCT report_date)` per operatore, con "Media h/giorno" separata.
2. **Feature nuova**: sezione "Riepilogo per Codice Contratto" con:
   - Codice nel formato **`WTS_3670 | WTS_CSS | NOME CLIENTE | DESCRIZIONE`**
     (`dgb_forms_contract.code | code_x_installation | clients.name | description`).
   - Fasce orarie: ore ordinarie, straordinario, reperibilità.
   - Giorni-uomo aggregati (COUNT DISTINCT date x operatore).
   - Costo contratto (da DGB: `dgb_forms_activity_operator.cost`).
   - **TotCostoTab** (costo parametrato: `cm_rate_band_rates.rate_hour`
     × ore, fallback su `dgb_operator.hourly_cost`).

## Contenuto pacchetto
```
pm_v1_9_28/
├── VERSION                        1.9.28
├── report_servizi_it.php          pagina completa (fix + feature)
├── sql/
│   └── migration_v1_9_28.sql      viste v_rsi_per_persona / v_rsi_per_contratto + RBAC + log
├── docs/README_v1_9_28.md         questa guida
└── tools/verify_v1_9_28.php       verifica installazione
```

## Installazione
1. **Backup**:
   ```powershell
   copy P:\xampp\htdocs\portalmanager\report_servizi_it.php P:\backup\report_servizi_it.bak 2>NUL
   mysqldump -uroot portalmanager > P:\backup\dump_pre_1_9_28.sql
   ```
2. **Deploy file**:
   ```powershell
   copy report_servizi_it.php P:\xampp\htdocs\portalmanager\
   ```
3. **Migration**:
   ```powershell
   mysql -uroot portalmanager < sql\migration_v1_9_28.sql
   ```
4. **Voce menu**: aggiungi in `MenuManager` (sezione "Reporting" o "Servizi IT"):
   ```json
   { "page": "report_servizi_it.php", "label": "Relazione Servizi IT" }
   ```
5. **Verifica**:
   ```powershell
   P:\xampp\php\php.exe tools\verify_v1_9_28.php
   ```
6. **Ricarica** OPcache / riavvia Apache:
   ```powershell
   net stop Apache2.4 ; net start Apache2.4
   ```

## Test funzionale
Apri **Relazione Servizi IT** con periodo che copre attività reali:
- Filtro Incaricato: seleziona un operatore e verifica che le "Giornate"
  corrispondano al numero effettivo di date lavorate (non alle ore/8).
- Riepilogo per Codice Contratto: verifica il formato
  `code | code_x_installation | cliente | description`.
- Confronta TotCostoTab con `SUM(ao.cost)` per stimare l'over/under
  rispetto alla tariffa contrattuale.

## Query di verifica manuale
```sql
-- Giornate uniche per persona nel periodo
SELECT TRIM(CONCAT_WS(' ', op.first_name, op.second_name)) nm,
       COUNT(DISTINCT a.report_date) giornate,
       SUM(ao.hours) ore
FROM dgb_forms_activity a
JOIN dgb_forms_activity_operator ao ON ao.id_activity=a.id
JOIN dgb_operator op ON op.id=ao.id_operator
WHERE a.report_date BETWEEN '2026-08-01' AND '2026-08-31'
  AND COALESCE(a.deleted,0)<>1
GROUP BY op.id
ORDER BY giornate DESC;

-- Riepilogo per contratto
SELECT * FROM v_rsi_per_contratto
WHERE contract_code LIKE 'WTS_%'
ORDER BY tot_costo_tab DESC;
```

## Rollback
```powershell
copy P:\backup\report_servizi_it.bak P:\xampp\htdocs\portalmanager\report_servizi_it.php
mysql -uroot portalmanager -e "DROP VIEW IF EXISTS v_rsi_per_persona; DROP VIEW IF EXISTS v_rsi_per_contratto;"
mysql -uroot portalmanager -e "DELETE FROM pm_migration_sql WHERE version='1.9.28';"
mysql -uroot portalmanager -e "UPDATE app_settings SET setting_value='1.9.27' WHERE setting_key='app_version';"
net stop Apache2.4 ; net start Apache2.4
```

## Note metodologiche

**Perché "giorni-uomo" ≠ `SUM(ore)/8`**
- 10 giornate x 4h di presidio: 40 ore totali, 10 giornate impegnate.
- 5 giornate x 8h di attività intensiva: 40 ore totali, 5 giornate impegnate.
La divisione ore/8 restituisce 5 in entrambi i casi, nascondendo la
differenza operativa (numero di giornate su cui il tecnico non è disponibile
per altro). La misura corretta di "giornate impegnate" è
`COUNT(DISTINCT date, operatore)`; la saturazione media (media h/giorno)
va mostrata come metrica separata.

**TotCostoTab**
- Fascia scelta con `cm_rate_bands.band_name = COALESCE(dgb_operator.type, 'Default')`.
- Regime: `Ordinario` di default; `Reperibilità` quando
  `dgb_forms_activity_operator.during_availability = 1`.
- Fallback: se non esiste la riga in `cm_rate_band_rates`, si usa
  `dgb_operator.hourly_cost`.

Se hai una tariffa Straordinario dedicata, estendi l'ENUM
`cm_rate_band_rates.regime` con `'Straordinario'` e adatta il CASE nella
vista `v_rsi_per_contratto`.
