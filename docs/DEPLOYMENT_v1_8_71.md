# Deployment — PortalManager v1.8.71

Release **solo applicativa**: nessuna variazione di schema né di dati. La
funzione introdotta però **può eliminare righe** se lanciata deliberatamente.

## 1. Contenuto

```
VERSION                          1.8.71
sync_commesse.php                (ROOT)  verifica e riallineamento
app/DatasetSync.php              + reconcile()
app/Version.php                  PM_VERSION = 1.8.71
gli altri file                   invariati da v1.8.70
sql/migration_v1_8_71.sql        solo bump di versione
sql/upgrade_1_7_56_to_1_8_71.sql consolidato cumulativo (497 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i tre file rispettando i percorsi.
3. SQL Runner: `sql/migration_v1_8_71.sql` (da v1.8.70) oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

## 3. Come si usa

**Sincronizzazione gestionale** → **Verifica allineamento**.

Non scrive nulla. Produce una tabella con, per ogni dataset:

| Colonna | Significato |
|---|---|
| Nel gestionale | righe che la sorgente restituisce oggi |
| Nel portale | righe presenti in tabella |
| **Orfane** | righe del portale che il gestionale non conosce più |
| Protette | righe inserite a mano o da XLSX, mai rimosse |
| Esempi di chiave orfana | fino a cinque chiavi, per riconoscere un pattern |

Solo se trova orfane compare **Riallinea al gestionale**, con il numero esatto di
righe da rimuovere.

## 4. Prima di riallineare

**Eseguire un backup del database.** L'operazione non è reversibile.

Guardare gli **esempi di chiave** prima di procedere: se mostrano un pattern
riconoscibile — un prefisso, un formato di codice — si sta rimuovendo un gruppo
omogeneo, ed è il caso normale. Se invece sembrano chiavi legittime sparse, vale
la pena capire perché la sorgente non le restituisce più prima di cancellarle.

Una causa possibile e innocua: un filtro nella query del dataset che esclude
righe che prima includeva. In quel caso le righe non sono orfane, è la query a
essere cambiata.

## 5. Che cosa non viene mai rimosso

Le righe con `import_batch_id` NULL, cioè inserite a mano o caricate da XLSX. Non
provengono dal gestionale, quindi la sua assenza non le rende orfane.

Il riquadro le conta nella colonna **Protette**.

## 6. Verifica post-deploy

| Passo | Esito atteso |
|---|---|
| Footer | `1.8.71` |
| Sincronizzazione gestionale | compare **Verifica allineamento** |
| Verifica su portale già allineato | orfane **0** per tutti i dataset |
| Verifica dopo la v1.8.70 | dovrebbe risultare allineato |

Se dopo la pulizia della v1.8.70 la verifica trova ancora orfane sui rapporti,
significa che la sincronizzazione ha reintrodotto righe con il vecchio formato:
va controllata la versione di `app/DgbSync.php` sul server.

## 7. Rollback

Ripristinare i tre file dalla copia precedente, poi:

```sql
UPDATE app_settings SET setting_value='1.8.70'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Se il riallineamento è già stato eseguito, le righe rimosse si recuperano solo
dal backup.
