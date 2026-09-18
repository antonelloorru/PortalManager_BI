# Deployment — PortalManager v1.9.6

## 1. Contenuto

```
VERSION                          1.9.6
service_desk.php                 (ROOT)  filtro e riepilogo di stampa
app/SdModel.php                  + 1 metodo
app/Version.php                  PM_VERSION = 1.9.6
gli altri file                   invariati da v1.9.5
sql/migration_v1_9_6.sql         solo bump di versione
sql/upgrade_1_7_56_to_1_9_6.sql  consolidato cumulativo
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare **`service_desk.php` in ROOT** e i due file in `app\`.
3. SQL Runner: `sql/migration_v1_9_6.sql` oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

## 3. Verifica del filtro

Nella barra dei filtri compare **Componente del team**, fra Coda e Livello.

Selezionando una persona, **tutti i riquadri** della pagina si restringono a lei —
indicatori, ripartizione, analisi del team, andamento.

Il menu riporta la sotto-unità accanto al nome e li ordina per cognome.

## 4. Verifica della stampa

**Report generale** (senza componente selezionato): dopo la tabella «I componenti
del Service Desk» compare **Riepilogo attività del periodo**, con cinque
indicatori e il dettaglio per componente.

Sotto ancora, **Attività del team per tipologia di contratto**.

## 5. Due tabelle, due periodi

Il report ne contiene ora due, ed è deliberato:

| Tabella | Periodo |
|---|---|
| I componenti del Service Desk | **intero archivio** |
| Riepilogo attività del periodo | **periodo selezionato** |

La prima dice chi sono i componenti e quanto pesano storicamente; la seconda cosa
hanno fatto nel periodo stampato. Sono domande diverse, ed entrambe le tabelle
dichiarano a quale rispondono.

## 6. Componenti con zero

Un componente che non ha lavorato nel periodo **resta in elenco con zero**.

Non ha lavorato, non è uscito dalla squadra: farlo sparire confonderebbe le due
cose, e chi legge non saprebbe se cercarlo altrove.

## 7. Chi non è nel team

Il menu comprende anche specialisti che hanno preso in carico ticket senza
appartenere all'unità Service Desk: sono marcati con il proprio livello.

Se filtrate su un tecnico che **non è più** nel team, resta nel menu marcato
«fuori squadra» — altrimenti il menu mostrerebbe «Tutta la squadra» mentre il
filtro è attivo.

## 8. Rollback

Ripristinare `service_desk.php` e `app/SdModel.php` dalla v1.9.5.

```sql
UPDATE app_settings SET setting_value='1.9.5'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
