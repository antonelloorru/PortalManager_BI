# Deployment — PortalManager v1.8.52

Release **solo applicativa**: nessuna variazione di schema né di dati.

## 1. Contenuto

```
VERSION                          1.8.52
dgb_activities.php               (ROOT)  drill-down, suggerimenti, linea spezzata
app/DgbModel.php                 riferimento sugli incaricati attivi
app/Version.php                  PM_VERSION = 1.8.52
gli altri file                   invariati da v1.8.51
sql/migration_v1_8_52.sql        solo bump di versione
sql/upgrade_1_7_56_to_1_8_52.sql consolidato cumulativo (380 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i file rispettando i percorsi.
3. SQL Runner: `sql/migration_v1_8_52.sql` (da v1.8.51) oppure
   `sql/upgrade_1_7_56_to_1_8_52.sql`.
4. **Stop + Start Apache**.
5. **Ctrl+F5** — il grafico è generato lato server, ma la pagina in cache
   mostrerebbe la versione precedente.

## 3. Verifica post-deploy

| Passo | Esito atteso |
|---|---|
| Footer | `1.8.52` |
| Gestione Commesse → Attività & Rendicontazione DGB | grafico visibile |
| Passare il mouse su una barra | valori esatti e percentuale di utilizzo |
| **Fare clic su una barra mensile** | si apre il dettaglio giornaliero di quel mese |
| Filtri impostati prima del clic | restano attivi nel dettaglio |
| In vista giornaliera, frecce ‹ › | spostano al mese precedente e successivo |
| Linea rossa in vista giornaliera | si interrompe nei giorni di chiusura |
| Fine settimana con ore | hanno un riferimento, non zero |
| Export XLSX/CSV della distribuzione | colonne *Incaricati attivi* e *Nota* |

### Il controllo che conta

Aprire il dettaglio giornaliero di un mese di lavoro normale e leggere l'utilizzo
nei suggerimenti: deve collocarsi **intorno al 90-100%** nei giorni feriali.

Prima di questa release lo stesso mese mostrava circa il 55%, perché il carico di
chi lavorava veniva confrontato con la capacità dell'intero organico del periodo.

Se i valori restano bassi in modo uniforme, il file non è stato sovrascritto.

## 4. Nota sui numeri che cambiano

**I totali delle ore non cambiano**: le barre riportano gli stessi valori di
prima. Cambia il riferimento, cioè la linea rossa e la percentuale di utilizzo.

Chi avesse annotato percentuali di utilizzo giornaliere dalle versioni precedenti
troverà valori diversi. Quelli nuovi sono corretti: i precedenti confrontavano
grandezze non confrontabili.

Le percentuali della **vista mensile** non cambiano: lì il calcolo era già
corretto.

## 5. Rollback

Ripristinare `dgb_activities.php` e `app/DgbModel.php` dalla copia precedente,
poi:

```sql
UPDATE app_settings SET setting_value='1.8.51'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Stop + Start Apache, Ctrl+F5.
