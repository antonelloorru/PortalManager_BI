# Deployment — PortalManager v1.8.91

## 1. Contenuto

```
VERSION                          1.8.91
app/SdModel.php                  ordinamento per cognome
app/ItServiceModel.php           ordinamento per cognome
app/Version.php                  PM_VERSION = 1.8.91
gli altri file                   invariati da v1.8.90
sql/migration_v1_8_91.sql        v_cm_nomi + 3 viste ricreate
sql/upgrade_1_7_56_to_1_8_91.sql consolidato cumulativo (602 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i tre file in `app\`.
3. SQL Runner: `sql/migration_v1_8_91.sql` (da v1.8.90) oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

Nessuna risincronizzazione: le viste leggono i dati già presenti.

## 3. Verifica

```sql
SELECT tecnico, ordina FROM v_cm_sd_operatori
 ORDER BY ordina IS NULL, ordina LIMIT 10;
```

Attesi in ordine alfabetico **per cognome**: Aloisio, Anzidei, Ascenzi, Ayed,
Baggiani, Baruchello…

Nelle schermate:

| Dove | Controllo |
|---|---|
| Service Desk → Operatività per tecnico | elenco alfabetico per cognome |
| Service Desk → I componenti | idem |
| Relazione IT → raggruppa per Incaricato | idem |
| Relazione IT → menu «Incaricato» | idem |

**Il nome resta mostrato come prima** — «Enrico Mancini», non «Mancini Enrico».
Cambia solo l'ordine.

## 4. Perché serviva una vista

Le due fonti scrivono il nome in ordini opposti: i moduli come *Cognome Nome*, i
ticket come *Nome Cognome*. Un ordinamento sulla colonna avrebbe messo alcune
persone in ordine di cognome e altre in ordine di nome.

`v_cm_nomi` risolve entrambe le forme usando `cm_professionals`, che tiene i due
campi separati.

## 5. Cognomi composti e maiuscole

Verificati entrambi i casi delicati:

| Caso | Risultato |
|---|---|
| `Valentina De Caprio` | ordina sotto **D**, non sotto C |
| `ZIN DANIELE` in maiuscolo | ordina dopo `Zhu Kevin`, non in fondo |

Il secondo si vedeva prima della correzione: nel confronto fra stringhe le
maiuscole precedono le minuscole, e i nomi digitati tutti maiuscoli finivano in
coda all'elenco.

## 6. Un'eccezione voluta

I raggruppamenti **non per persona** — modalità, linea di servizio, settore —
restano ordinati **per ore decrescenti**.

Un elenco di modalità in ordine alfabetico costringerebbe a cercare quale pesa di
più. L'ordinamento giusto dipende da cosa si sta cercando.

## 7. Rollback

Ripristinare i tre file dalla v1.8.90 e rieseguire la migration v1.8.89 e v1.8.82
per riportare le viste alla forma precedente, poi:

```sql
DROP VIEW IF EXISTS v_cm_nomi;
UPDATE app_settings SET setting_value='1.8.90'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
