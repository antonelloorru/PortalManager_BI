# Deployment — PortalManager v1.8.41

Ambiente: XAMPP per Windows · PHP 8.2 · Apache 2.4.58 · MariaDB 10.4.32.
**Questa release si applica all'istanza `portalmanager`**, per allinearla a
`demo_portalmanager`. L'istanza demo è già corretta e non va toccata.

## 1. Contenuto del pacchetto

```
VERSION                              1.8.41
import_commesse.php                  (ROOT)  import auto-riconciliante
app/Version.php                              PM_VERSION = 1.8.41
sql/migration_v1_8_41.sql                    allineamento dati (63 statement)
sql/upgrade_1_7_56_to_1_8_41.sql             consolidato cumulativo (313 statement)
docs/                                        questa documentazione
```

I file PHP sono completi e già patchati: si sovrascrivono, non si applicano diff.

## 2. Backup preliminare (obbligatorio)

La migration modifica ed elimina righe in `cm_projects` e rimappa 11 tabelle
dipendenti. Prima di procedere:

1. esportare il DB `portalmanager` da phpMyAdmin (Esporta → SQL);
2. verificare che il file esportato non sia vuoto e sia leggibile.

Non esiste un rollback puntuale della riconciliazione: il ripristino avviene dal
backup del database.

## 3. Aggiornamento

1. Accedere come Super Admin → `system_console.php` → tab **Aggiornamento**.
2. Sovrascrivere i file, rispettando i percorsi: `import_commesse.php` nella
   radice del portale, `Version.php` in `app\`.
3. Eseguire nel **SQL Runner**:
   - da v1.8.40 → `sql/migration_v1_8_41.sql`
   - da versione precedente o incerta → `sql/upgrade_1_7_56_to_1_8_41.sql`
4. **Stop + Start Apache**.
5. **Ctrl+F5** sul browser.

La migration è di dimensioni consistenti (circa 700 KB, 1.062 commesse e 305
clienti). Se il SQL Runner va in timeout, aumentare `max_execution_time` in
`php.ini` oppure eseguire il file da phpMyAdmin → Importa.

## 4. Verifica post-deploy

| Controllo | Esito atteso |
|---|---|
| Footer | `1.8.41` |
| Commesse / Progetti → totale | 1.064 commesse |
| Codici in elenco | `WTS_…`, `NIS_…`, `WEN_…`, `ANT_…` (non più `DGB-…`) |
| Segnaposto residui | 2 soli: `DGB-1140`, `DGB-1147` |
| Colonne economiche | valorizzate (valore, margine, consuntivato, stato economico) |
| Colonna cliente | popolata; anagrafica clienti con 305 voci |
| Gantt commesse | barre visibili, 1.062 commesse con date |
| Scheda commessa `WTS_3016` | 6.805 rapporti di intervento |
| Carico risorse | commesse con codici reali nel dettaglio di cella |

Query di controllo rapido:

```sql
SELECT COUNT(*) AS totali,
       SUM(project_code LIKE 'DGB-%') AS segnaposto,
       SUM(value_total IS NOT NULL)   AS con_valore
  FROM cm_projects;
```

Atteso: `1064 / 2 / 1062`.

## 5. Import successivi

Da questa versione **Gestione Commesse → Import commesse XLSX** riconcilia da solo
eventuali nuovi segnaposto: al termine il messaggio riporta quanti ne sono stati
assorbiti. I due segnaposto residui verranno assorbiti automaticamente non appena
le rispettive commesse compariranno nel file del gestionale.

## 6. Rollback

1. Ripristinare il database dal backup del punto 2.
2. Ripristinare i due file PHP dalla copia precedente.
3. Riportare l'etichetta di versione:

```sql
UPDATE app_settings SET setting_value='1.8.40'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

4. Stop + Start Apache, Ctrl+F5.
