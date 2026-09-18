# Deployment — PortalManager v1.8.96

**Release di fondamenta**: viste e parametri. La pagina non è inclusa.

## 1. Contenuto

```
VERSION                          1.8.96
app/Version.php                  PM_VERSION = 1.8.96
gli altri file                   invariati da v1.8.95
sql/migration_v1_8_96.sql        5 viste + 5 parametri
sql/upgrade_1_7_56_to_1_8_96.sql consolidato cumulativo (630 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare `app/Version.php`.
3. SQL Runner: `sql/migration_v1_8_96.sql` (da v1.8.95) oppure il consolidato.
4. **Stop + Start Apache**.

## 3. Verifica

```sql
SELECT * FROM v_cm_pres_quadro\G
```

| Atteso | |
|---|---|
| Commesse | 48 (28 aperte) |
| Valore | 7.022.269 € |
| Costo interno | 3.479.331 € |
| Headcount | 35 |
| Giornate di copertura | 4.820 |
| Allocazioni | 20 fisse, 5 miste, 16 in rotazione |

## 4. I parametri sono modificabili

```sql
SELECT setting_key, setting_value FROM app_settings
 WHERE setting_key LIKE 'presidio_%';
```

| Parametro | Predefinito | Cosa governa |
|---|---|---|
| `presidio_linee` | `WTS-PRES` | quali linee sono presidio (accorpa i body rental) |
| `presidio_soglia_esclusivita` | `80` | sopra questa quota è personale di presidio |
| `presidio_soglia_fissa` | `80` | sopra questa quota l'allocazione è fissa |
| `presidio_soglia_rotazione` | `60` | sotto questa quota è rotazione |
| `presidio_giornata_ore` | `8` | ore che compongono una giornata |

Sono **convenzioni**, non proprietà dei dati: se la vostra prassi usa soglie
diverse, si cambiano qui.

Se i body rental avessero una linea propria:

```sql
UPDATE app_settings SET setting_value = 'WTS-PRES,WTS-BR'
 WHERE setting_key = 'presidio_linee';
```

## 5. Perché la soglia e non il criterio letterale

La regola dichiarata era «non registrano interventi su commesse diverse dalla
propria». Nei dati questo caso è raro:

**Balestrieri Paolo ha il 99,7% delle ore su presidio ma compare su due
commesse.** Con il criterio letterale sarebbe escluso dall'headcount — l'opposto
di quanto serve.

La soglia all'80% include chi ha qualche sconfinamento marginale ed esclude chi fa
il presidio occasionalmente.

## 6. La classificazione in quattro categorie

```sql
SELECT classificazione, COUNT(*) FROM v_cm_pres_persone GROUP BY classificazione;
```

Sul database di prova: 35 «presidio di fatto», 0 «confermati», perché nessun
profilo ha l'unità assegnata.

**Sul vostro server le assegnazioni ci sono**, quindi vedrete la ripartizione
vera. In particolare:

- **assegnato non operante** = è nell'unità Presidio ma non ne fa le ore:
  anagrafica da aggiornare o persona che ha cambiato ruolo
- **presidio di fatto** = ne fa le ore ma non è assegnato: assegnazione mancante

Entrambe sono segnalazioni utili, non errori del calcolo.

## 7. Fissa o rotazione

Non conto le persone ma la **quota della principale**.

**WTS_3043**: sette persone e 666 giornate di copertura, ma Passiatore copre il
78,5% → **«fissa con sostituzioni»**. Contando le persone sarebbe stata
«rotazione», che descrive male la realtà.

## 8. Le viste disponibili

| Vista | Contenuto |
|---|---|
| `v_cm_pres_quadro` | i cinque KPI in una riga |
| `v_cm_pres_commesse` | ricavo, costo, margine per commessa |
| `v_cm_pres_persone` | headcount e classificazione |
| `v_cm_pres_allocazioni` | chi lavora su cosa, con quota |
| `v_cm_pres_scheda` | commessa con allocazione e coperture |

## 9. Rollback

```sql
DROP VIEW IF EXISTS v_cm_pres_quadro;
DROP VIEW IF EXISTS v_cm_pres_scheda;
DROP VIEW IF EXISTS v_cm_pres_allocazioni;
DROP VIEW IF EXISTS v_cm_pres_persone;
DROP VIEW IF EXISTS v_cm_pres_commesse;
DELETE FROM app_settings WHERE setting_key LIKE 'presidio_%';
UPDATE app_settings SET setting_value='1.8.95'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
