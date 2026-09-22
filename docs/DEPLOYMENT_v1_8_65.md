# Deployment — PortalManager v1.8.65

Release **solo applicativa**: nessuna variazione di schema né di dati.

## 1. Contenuto

```
VERSION                          1.8.65
sync_commesse.php                (ROOT)  form corretti, esito inequivocabile
app/Version.php                  PM_VERSION = 1.8.65
gli altri file                   invariati da v1.8.64
sql/migration_v1_8_65.sql        solo bump di versione
sql/upgrade_1_7_56_to_1_8_65.sql consolidato cumulativo (466 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare `sync_commesse.php` in ROOT e `app/Version.php` in `app\`.
3. SQL Runner: `sql/migration_v1_8_65.sql` (da v1.8.64) oppure il consolidato.
4. **Stop + Start Apache**.
5. **Ctrl+F5** — necessario: la pagina è cambiata nella struttura dei form.

## 3. Verifica post-deploy

| Passo | Esito atteso |
|---|---|
| Footer | `1.8.65` |
| Sincronizzazione gestionale | due pulsanti affiancati come prima |
| **Anteprima completa** | titolo con etichetta **ANTEPRIMA** arancione |
| **Sincronizza tutto** | titolo con etichetta **SCRITTURA** verde |

L'etichetta è il controllo: dice che cosa è stato eseguito, non che cosa si
credeva di aver premuto.

Dopo *Sincronizza tutto* i numeri devono essere quelli reali — decine di migliaia
di righe per allocazioni e rapporti — e non fermarsi a 200 per dataset come
nell'anteprima.

## 4. Verifica sui dati

```sql
SELECT COUNT(*) FROM cm_project_allocations;   -- atteso ~69.300
SELECT COUNT(*) FROM cm_contract_rates;        -- atteso ~24.300
SELECT COUNT(*) FROM cm_divisions;             -- atteso 8
```

Se sono a zero o a 200, è stata eseguita un'anteprima.

L'event log riporta ora la modalità:

```sql
SELECT created_at, message FROM event_log
 WHERE message LIKE 'Sync completa richiesta%'
 ORDER BY id DESC LIMIT 5;
```

## 5. Che cosa era successo

I due pulsanti erano nello stesso form. Il valore di un pulsante viene trasmesso
solo se il browser lo riconosce come *submitter*: negli altri casi il server
riceve il **primo** pulsante del form, che era *Anteprima completa*.

Ora l'azione sta in un campo nascosto, sempre trasmesso.

## 6. Rollback

Ripristinare `sync_commesse.php` dalla copia precedente, poi:

```sql
UPDATE app_settings SET setting_value='1.8.64'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Attenzione: tornare indietro significa riavere il pulsante che esegue
l'anteprima.
