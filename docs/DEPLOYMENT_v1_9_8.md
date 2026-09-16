# Deployment — PortalManager v1.9.8

## 1. Contenuto

```
VERSION                          1.9.8
header.php                       (ROOT)  inclusione di pm-filters.css
service_desk.php                 (ROOT)  pannello uniformato + classe gestione
it_service.php                   (ROOT)  pannello uniformato + ricerca, cliente
dir_report.php                   (ROOT)  pannello uniformato + ricerca, cliente, azienda
assets/pm-filters.css            NUOVO — stile condiviso del pannello
app/DirModel.php                 + filtri q, cliente
app/ItServiceModel.php           + filtri q, cliente
app/Version.php                  PM_VERSION = 1.9.8
gli altri file                   invariati da v1.9.7
sql/migration_v1_9_8.sql         solo bump di versione
sql/upgrade_1_7_56_to_1_9_8.sql  consolidato cumulativo
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i **quattro file in ROOT** e i tre in `app\`.
3. Copiare **`assets/pm-filters.css`** nella cartella `assets\`.
4. SQL Runner: `sql/migration_v1_9_8.sql` oppure il consolidato.
5. **Stop + Start Apache**, **Ctrl+F5**.

La cartella `assets\` esiste dalla v1.9.3: se l'aveste saltata, va creata ora.

## 3. Verifica

**Service Desk**, **Relazione di Servizio IT**, **Report direzionale**: i filtri
sono ora in un pannello a scomparsa uguale a quello di Commesse/Progetti.

| Controllo | Atteso |
|---|---|
| Pannello chiuso senza filtri | sì |
| Pannello **aperto** con filtri attivi | sì, con contatore |
| Riepilogo a destra | numero di righe nel perimetro |
| Menu multipli | altezza sufficiente a vedere più righe |

## 4. I campi aggiunti

**Report direzionale**: Cerca ovunque (codice, denominazione, cliente), Cliente,
Azienda esecutrice.

**Relazione IT**: Cerca ovunque (commessa, cliente, modulo), Cliente.

**Service Desk**: **Classe di gestione** — il filtro esisteva dalla v1.8.84 ma
era raggiungibile solo modificando l'URL a mano.

## 5. Perché il pannello si apre da solo

Se un filtro è attivo, il pannello è aperto e il contatore lo dice.

Un pannello chiuso che nasconde filtri attivi fa credere di guardare tutti i dati:
è lo stesso principio per cui il perimetro dell'agente è dichiarato in testa alla
sua scheda.

## 6. Personalizzare la griglia

```css
/* assets/pm-filters.css */
.pm-grid-auto { grid-template-columns:repeat(auto-fit,minmax(165px,1fr)) }
```

`minmax(165px, 1fr)` decide quanti filtri stanno per riga: alzando il minimo se
ne vedono meno ma più larghi.

## 7. Rollback

Ripristinare i quattro file in ROOT e i tre in `app\` dalla v1.9.7, e rimuovere
`assets/pm-filters.css`.

```sql
UPDATE app_settings SET setting_value='1.9.7'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
