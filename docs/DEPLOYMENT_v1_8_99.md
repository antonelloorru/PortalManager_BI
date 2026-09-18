# Deployment — PortalManager v1.8.99

## 1. Contenuto

```
VERSION                          1.8.99
app/Version.php                  PM_VERSION = 1.8.99
gli altri file                   invariati da v1.8.98
sql/migration_v1_8_99.sql        1 tabella + 20 righe + 2 viste + allineamento
sql/upgrade_1_7_56_to_1_8_99.sql consolidato cumulativo (648 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare `app/Version.php`.
3. SQL Runner: `sql/migration_v1_8_99.sql` (da v1.8.98) oppure il consolidato.
4. **Stop + Start Apache**.

## 3. Verifica

```sql
SELECT descrizione, code_doc, service_line, formula, cost_basis
  FROM cm_calc_reference ORDER BY sort_order;
```

Attese **20 righe**, con la ripartizione:

| Formula | Righe |
|---|---|
| A — valore − consuntivato | 1 |
| B — valore − costi | 6 |
| C — valore + consuntivato − costi | 12 |
| D — consuntivato − costi | 1 |

```sql
SELECT * FROM v_cm_calc_copertura;
```

**Guardate la riga `predefinita`**: sono le commesse la cui linea non ha una
regola nella tabella. Se sono molte, mancano righe da aggiungere.

## 4. Cosa cambia rispetto a prima

`cm_contract_models.cost_basis` conteneva `'direzionale'` su WTS-GES e WTS-SOC.
Il documento dice **«Costi a zero»**, che è cosa diversa: la migration allinea i
valori al testo ufficiale.

Le altre linee erano già coerenti.

## 5. Le linee non coperte

Il documento elenca 20 tipi di contratto. Se nei vostri dati esistono linee di
servizio che non vi compaiono, ricadono sulla regola **predefinita** — formula B,
base fascia — e il pannello lo dichiara.

Per aggiungerne una:

```sql
INSERT INTO cm_calc_reference
  (descrizione, code_doc, service_line, formula, formula_desc,
   cost_basis, cost_desc, sort_order)
VALUES ('Nuovo tipo', 'WTS-XX', 'WTS-XX', 'B',
        'Valore a oggi meno costi consuntivati e storni',
        'fascia', 'Fascia di costo interna', 210);
```

## 6. I codici «Dinamico»

Sei righe del documento non hanno un codice fisso: la commessa viene aperta di
volta in volta. Le linee `NV_*` che non hanno una riga propria ricadono
automaticamente sul trattamento del gruppo — formula C, base full cost — e la
colonna `regola_origine` riporta **«gruppo dinamico»**.

Se una di quelle attività dovesse avere un codice stabile, si aggiunge in
`service_line` sulla riga corrispondente.

## 7. Limite dichiarato

**Il consolidato completo non è stato rieseguito su un database reale**: il dump
del portale non era più disponibile in ambiente di prova.

È stata invece collaudata **la coda v1.8.99 in isolamento**, su schema costruito
appositamente: err=0, idempotente su due esecuzioni, 20 righe inserite e
risoluzione delle regole verificata su dieci commesse di prova.

La migration singola è stata collaudata per intero, tre volte.

## 8. Rollback

```sql
DROP VIEW IF EXISTS v_cm_calc_copertura;
DROP VIEW IF EXISTS v_cm_calc_regola;
DROP TABLE IF EXISTS cm_calc_reference;
UPDATE app_settings SET setting_value='1.8.98'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

L'allineamento di `cm_contract_models.cost_basis` non viene annullato: i valori
riflettono il documento aziendale e sono corretti indipendentemente da questa
release.
