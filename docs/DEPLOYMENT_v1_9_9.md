# Deployment — PortalManager v1.9.9

**Correttiva**: il filtro temporale veniva ignorato da grafici, tabelle e stampe.

## 1. Contenuto

```
VERSION                          1.9.9
service_desk.php                 (ROOT)  chiamate con il periodo
app/SdModel.php                  6 metodi corretti
app/Version.php                  PM_VERSION = 1.9.9
gli altri file                   invariati da v1.9.8
sql/migration_v1_9_9.sql         solo bump di versione
sql/upgrade_1_7_56_to_1_9_9.sql  consolidato cumulativo
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare **`service_desk.php` in ROOT** e i due file in `app\`.
3. SQL Runner: `sql/migration_v1_9_9.sql` oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

## 3. Verifica

**Service Desk** → impostare un periodo stretto, per esempio un mese.

| Controllo | Atteso |
|---|---|
| Grafico dell'andamento | **solo i mesi del periodo** |
| Tabella dei componenti | valori del periodo |
| Analisi del Team | valori del periodo |
| Code seguite | ticket del periodo |
| Report di stampa | gli stessi numeri della schermata |

Il controllo che conta: **il grafico deve avere tanti punti quanti sono i mesi del
periodo scelto**. Prima ne mostrava dodici comunque.

## 4. Cosa era successo

`trend()` prendeva dodici mesi a ritroso dalla data **finale**, ignorando quella
iniziale: chi sceglieva un trimestre vedeva un anno.

Altri cinque metodi non ricevevano affatto i filtri e leggevano viste aggregate
sull'intero archivio.

## 5. La tabella dei componenti era vuota

**Verificate che «I componenti del Service Desk» ora si popoli.**

`operativita()` ordinava per una colonna che nella vista non esiste: la query
falliva e il `try/catch` restituiva un elenco vuoto. La tabella era vuota da prima
di questa release, senza alcun errore a schermo.

Se prima non la vedevate, era questo.

## 6. La quota delle code

Ora è rapportata al totale della coda **nello stesso periodo**, non a quello
storico.

Su un trimestre le percentuali saranno quindi più alte di prima: prima un
trimestre veniva diviso per il totale di tre anni.

## 7. Le note nella pagina

Le diciture «dati sull'intero archivio» sono state sostituite con «periodo
selezionato». Erano corrette prima della correzione, non lo sono più.

## 8. Rollback

Ripristinare `service_desk.php` e `app/SdModel.php` dalla v1.9.8 — ma il difetto
tornerebbe.

```sql
UPDATE app_settings SET setting_value='1.9.8'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
