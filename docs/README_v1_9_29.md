# PortalManager v1.9.29 — Relazione Servizi IT: dettaglio per Commessa

## Novità
Aggiunta **Sezione 3 — Dettaglio per Commessa** nella pagina `report_servizi_it.php`:
- Righe raggruppate per contratto DGB.
- Header di gruppo con formato:
  `CODICE | INSTALLAZIONE | CLIENTE | DESCRIZIONE` (es. `WTS_3670 | WTS_CSS | ACME S.p.A. | Assistenza sistemistica H24`).
- Colonne per riga: **Data, Operatore, Ticket, Fascia, Regime, Ore, Costo contratto (€), TotCostoTab (€)**.
- Totali per commessa in tfoot.
- Bottone **Esporta CSV** che rispetta i filtri correnti (BOM UTF-8, separatore `;`).

Aggiunta vista SQL `v_rsi_dettaglio_commessa` con colonna `riga_formattata`
che genera esattamente la stringa richiesta:
```
WTS_3670 | WTS_CSS | ACME S.p.A. | Assistenza sistemistica H24 |  | TCK-2026-00001 | Senior | 4.00 | 160.00 € | 180.00 €
```

## Contenuto pacchetto
```
pm_v1_9_29/
├── VERSION                              1.9.29
├── report_servizi_it.php                pagina completa (sez.1 + sez.2 + sez.3 nuova)
├── sql/migration_v1_9_29.sql            vista v_rsi_dettaglio_commessa + log
├── docs/README_v1_9_29.md
└── tools/verify_v1_9_29.php
```

## Installazione
```powershell
copy pm_v1_9_29\report_servizi_it.php   P:\xampp\htdocs\portalmanager\
mysql -uroot portalmanager < pm_v1_9_29\sql\migration_v1_9_29.sql
net stop Apache2.4 ; net start Apache2.4
```

## Verifica
```powershell
P:\xampp\php\php.exe pm_v1_9_29\tools\verify_v1_9_29.php --db=portalmanager --user=root --pass=
```
Attesi: 12 check verdi.

## Query rapida per confronto/export
```sql
-- Elenco riga_formattata per una commessa specifica
SELECT riga_formattata FROM v_rsi_dettaglio_commessa
WHERE contract_code LIKE 'WTS_3670%'
  AND report_date BETWEEN '2026-08-01' AND '2026-08-31'
ORDER BY report_date, activity_id;

-- Aggregato per commessa (con dettagli visibili in pagina)
SELECT contract_code, COUNT(*) righe,
       SUM(ore) ore_tot,
       SUM(costo_contratto) costo_tot,
       SUM(tot_costo_tab) tab_tot
FROM v_rsi_dettaglio_commessa
GROUP BY contract_code
ORDER BY ore_tot DESC;
```

## Test in laboratorio
Migration RUN1 + RUN2 idempotenti. Vista popolata: 5 righe per contratto
di test, colonna `riga_formattata` produce esattamente la stringa richiesta
(campo vuoto tra `DESCRIZIONE` e `TICKET`, importi con `€`).

## Rollback
```powershell
mysql -uroot portalmanager -e "DROP VIEW IF EXISTS v_rsi_dettaglio_commessa;"
mysql -uroot portalmanager -e "DELETE FROM pm_migration_sql WHERE version='1.9.29';"
mysql -uroot portalmanager -e "UPDATE app_settings SET setting_value='1.9.28' WHERE setting_key='app_version';"
copy P:\backup\report_servizi_it.bak P:\xampp\htdocs\portalmanager\report_servizi_it.php
net stop Apache2.4 ; net start Apache2.4
```
