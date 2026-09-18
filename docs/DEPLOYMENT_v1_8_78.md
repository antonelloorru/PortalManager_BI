# Deployment — PortalManager v1.8.78

**Release correttiva sui dati**: elimina 77 righe duplicate. Fare un backup.

## 1. Contenuto

```
VERSION                          1.8.78
app/DgbModel.php                 extra_hours non piu sommate a hours
app/Version.php                  PM_VERSION = 1.8.78
gli altri file                   invariati da v1.8.77
sql/migration_v1_8_78.sql        deduplica + vincolo + 3 viste
sql/upgrade_1_7_56_to_1_8_78.sql consolidato cumulativo (542 statement)
docs/                            questa documentazione
```

## 2. Prima di aggiornare

**Backup.** Poi annotare lo stato:

```sql
SELECT COUNT(*) AS allocazioni, ROUND(SUM(hours),1) AS ore
  FROM dgb_forms_activity_operator;

SELECT COUNT(*) AS gruppi_duplicati, SUM(n)-COUNT(*) AS righe_eccesso
  FROM (SELECT id_activity, id_operator, COUNT(*) n
          FROM dgb_forms_activity_operator GROUP BY 1,2 HAVING n>1) t;
```

Attesi: 71.294 allocazioni, 344.738 ore, **77 gruppi duplicati**.

## 3. Aggiornamento

1. Backup.
2. `system_console.php` → tab **Aggiornamento**.
3. Copiare i due file in `app\`.
4. SQL Runner: `sql/migration_v1_8_78.sql` (da v1.8.77) oppure il consolidato.
5. **Stop + Start Apache**, **Ctrl+F5**.

## 4. Verifica

```sql
SELECT COUNT(*) AS allocazioni, ROUND(SUM(hours),1) AS ore
  FROM dgb_forms_activity_operator;
```

Attesi: **71.217 allocazioni** (77 in meno), **344.375,5 ore** (362,5 in meno).

```sql
SELECT * FROM v_dgb_allocazioni_duplicate;
```

**Zero righe.**

Il caso segnalato:

```sql
SELECT * FROM v_dgb_ore_tecnico_giorno
 WHERE operatore_id = 2458 AND giorno = '2026-07-15';
```

Atteso: 6 moduli, **13,00 ore consuntivate**, 9,00 ordinarie, 4,00 extra,
11,20 in orario, 1,80 fuori orario.

## 5. Le nuove viste

**`v_dgb_ore_dettaglio`** — una riga per allocazione con: ore consuntivate, ore
ordinarie di contratto, ore extra, **ore in orario**, **ore fuori orario**.

**`v_dgb_ore_tecnico_giorno`** — le stesse grandezze aggregate per tecnico e
giorno. È la vista da usare per i prospetti richiesti.

```sql
SELECT * FROM v_dgb_ore_tecnico_giorno
 WHERE giorno BETWEEN '2026-07-01' AND '2026-07-31'
 ORDER BY ore_fuori_orario DESC LIMIT 20;
```

## 6. Due letture diverse, entrambe esposte

| Coppia | Significato |
|---|---|
| ordinarie / extra | **contrattuale**: quante ore sono straordinario |
| in orario / fuori orario | **temporale**: quante cadono nelle fasce 09–13 e 14–18 |

Non vanno sommate fra loro né confuse. Un intervento 09:00–19:00 ha 8 ore in
orario e 1 fuori, indipendentemente da quante siano extra.

## 7. Le ore caleranno di 362,5

**È l'effetto voluto**: erano righe contate due volte. Le ore possono solo
diminuire.

I prospetti per tecnico e per commessa mostreranno valori leggermente inferiori —
lo 0,1% del totale, concentrato però su 77 moduli specifici, dove la differenza è
del 50%.

## 8. Rollback

Ripristinare il database dal backup e i due file.

```sql
DROP VIEW IF EXISTS v_dgb_allocazioni_duplicate;
DROP VIEW IF EXISTS v_dgb_ore_tecnico_giorno;
DROP VIEW IF EXISTS v_dgb_ore_dettaglio;
ALTER TABLE dgb_forms_activity_operator DROP INDEX uq_dfao_activity_operator;
UPDATE app_settings SET setting_value='1.8.77'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
