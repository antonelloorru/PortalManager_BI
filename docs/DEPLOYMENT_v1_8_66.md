# Deployment — PortalManager v1.8.66

**Release correttiva su dati**: elimina i duplicati che le v1.8.50 e v1.8.51 non
erano riuscite a rimuovere.

## 1. Contenuto

```
VERSION                          1.8.66
app/Version.php                  PM_VERSION = 1.8.66
gli altri file                   invariati da v1.8.65
sql/migration_v1_8_66.sql        allineamento collation + deduplica + vincolo
sql/upgrade_1_7_56_to_1_8_66.sql consolidato corretto (480 statement)
docs/                            questa documentazione
```

**Il consolidato è stato corretto anche nei blocchi v1.8.50 e v1.8.51**: chi
installa da zero non incontrerà più il difetto.

## 2. Prima di aggiornare

**Esportare il database.** La migration elimina righe duplicate.

**Annotare i totali:**

```sql
SELECT COUNT(*) AS righe, ROUND(SUM(quantity_hours),2) AS ore
  FROM cm_intervention_reports;
```

## 3. Aggiornamento

1. Backup del database.
2. `system_console.php` → tab **Aggiornamento**.
3. Copiare `app/Version.php`.
4. SQL Runner: `sql/migration_v1_8_66.sql` (da v1.8.65) oppure il consolidato.
5. **Stop + Start Apache**, **Ctrl+F5**.

### Tempi

Il primo statement converte la collation di `cm_intervention_reports`: MariaDB
**riscrive l'intera tabella**. Su decine di migliaia di righe richiede qualche
minuto, durante i quali la tabella è bloccata in scrittura.

Se il SQL Runner va in timeout, aumentare `max_execution_time` in `php.ini` o
eseguire da phpMyAdmin.

## 4. Verifica post-deploy

```sql
SELECT * FROM v_cm_collation_check;
```

**Zero righe.** Elenca le colonne di collegamento con collation divergente: era
la condizione che rendeva possibile il conflitto.

```sql
SELECT TABLE_COLLATION FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cm_intervention_reports';
```

Atteso `utf8mb4_unicode_ci`.

```sql
SELECT * FROM v_cm_grana_check;
```

| Campo | Atteso |
|---|---|
| `grane_distinte` | uguale a `righe_totali` |
| `duplicati` | **0** |
| `senza_grana` | **0** |

E il vincolo, che le migration precedenti non erano riuscite ad applicare:

```sql
SHOW INDEX FROM cm_intervention_reports WHERE Key_name = 'uq_ir_source_uid';
```

## 5. Le ore possono calare

**È l'effetto voluto.** Le migration v1.8.50 e v1.8.51 fallivano sulla `DELETE`
di deduplica, quindi i duplicati non sono mai stati rimossi: sono lì da tre
release.

Le ore possono **diminuire, mai aumentare**. Se aumentano, ripristinare il backup.

Per vedere in anticipo cosa verrà consolidato:

```sql
SELECT source_uid, COUNT(*) AS righe
  FROM cm_intervention_reports
 WHERE source_uid IS NOT NULL AND source_uid <> ''
 GROUP BY source_uid HAVING COUNT(*) > 1
 ORDER BY righe DESC LIMIT 50;
```

## 6. Rollback

Ripristinare il database dal backup e `app/Version.php`, poi:

```sql
UPDATE app_settings SET setting_value='1.8.65'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Il ripristino del database è necessario: i duplicati eliminati non si
ricostruiscono. La collation convertita può restare — è quella corretta.
