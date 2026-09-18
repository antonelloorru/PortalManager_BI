# Deployment — PortalManager v1.8.88

Release **solo applicativa**: nessuna variazione di schema né di dati.

## 1. Contenuto

```
VERSION                          1.8.88
service_desk.php                 (ROOT)  filtro tecnico + report stampabili
app/SdModel.php                  filtro nel modello
app/Version.php                  PM_VERSION = 1.8.88
gli altri file                   invariati da v1.8.87
sql/migration_v1_8_88.sql        solo bump di versione
sql/upgrade_1_7_56_to_1_8_88.sql consolidato cumulativo (588 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare **`service_desk.php` in ROOT** e i due file in `app\`.
3. SQL Runner: `sql/migration_v1_8_88.sql` (da v1.8.87) oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

## 3. Verifica del filtro

Aprire la scheda di un componente: **ogni riquadro della pagina** deve riferirsi a
lui.

| Componente | Ticket | Presi | Escalation | Operatori |
|---|---|---|---|---|
| (nessun filtro) | 3.512 | 1.470 | 7,1% | 40 |
| Sebastiano Chiarini | **520** | **520** | **6,2%** | **1** |
| Emanuele Bressi | 278 | 278 | 10,8% | 1 |

Il controllo che conta: **i quattro indicatori in testa devono coincidere con
quelli della scheda**. Prima mostravano il totale generale.

## 4. Verifica dei report

Con un tecnico selezionato compare **Report personale**, altrimenti **Report
generale**. Si apre in una scheda nuova con un pulsante *Stampa* in alto a destra,
che non viene stampato.

| Controllo | Atteso |
|---|---|
| Nessun menu né barra laterale | il foglio è tutto contenuto |
| Intestazione | periodo, coda se filtrata, data di generazione |
| Sezioni non spezzate | nessun titolo orfano a fine pagina |
| Piè di pagina | avvertenze su durata e SLA |

Per i colori di sfondo nelle barre, attivare **«Grafica di sfondo»** nelle opzioni
di stampa del browser: è disattivata per impostazione predefinita.

## 5. Export XLSX

Con un tecnico selezionato l'export ha **otto fogli** invece di quattro: si
aggiungono Scheda, Contratti, Code e Ticket presi. Il nome del file riporta la
persona.

## 6. Una nota tecnica

Il filtro usa un **JOIN** e non `IN` o `EXISTS`. Su `v_cm_sd_ticket`, che è una
vista costruita su altre viste, MariaDB risolve male le sottoquery: `IN` ed
`EXISTS` restituivano 2 ticket dove il join ne trova 520.

Verificato in SQL puro. Se in futuro si aggiungessero filtri su queste viste,
conviene usare la stessa forma.

## 7. Rollback

Ripristinare `service_desk.php` e `app/SdModel.php` dalla v1.8.87, poi:

```sql
UPDATE app_settings SET setting_value='1.8.87'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
