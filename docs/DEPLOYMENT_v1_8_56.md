# Deployment — PortalManager v1.8.56

Release **solo applicativa**: nessuna variazione di schema né di dati.

## 1. Contenuto

```
VERSION                          1.8.56
dgb_activities.php               (ROOT)  filtri ed export delle anomalie
app/Version.php                  PM_VERSION = 1.8.56
gli altri file                   invariati da v1.8.55
sql/migration_v1_8_56.sql        solo bump di versione
sql/upgrade_1_7_56_to_1_8_56.sql consolidato cumulativo (400 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i due file rispettando i percorsi.
3. SQL Runner: `sql/migration_v1_8_56.sql` (da v1.8.55) oppure
   `sql/upgrade_1_7_56_to_1_8_56.sql`.
4. **Stop + Start Apache**.
5. **Ctrl+F5**.

## 3. Verifica post-deploy

| Passo | Esito atteso |
|---|---|
| Footer | `1.8.56` |
| Attività & Rendicontazione DGB → Anomalie orarie | compare il riquadro **Filtri** |
| Campo **Tecnico** | elenco a discesa con i nominativi presenti |
| Applicare un filtro | il conteggio accanto ai pulsanti si aggiorna |
| Intestazione della tabella | riporta *«N di M righe»* |
| Cliccare una scheda di riepilogo | filtra per tipo **mantenendo** gli altri filtri |
| **Esporta XLSX** | file `anomalie_orarie_<timestamp>.xlsx` apribile |
| **Esporta CSV** | separatore `;`, accenti corretti |

### La verifica che conta

Impostare un filtro che selezioni **più di 500 segnalazioni** — per esempio
severità *alta* senza altri criteri — ed esportare.

- l'intestazione della tabella dice *«500 di N righe (prime 500 a video)»*;
- il file esportato contiene **N righe**, non 500.

È il comportamento voluto: a video il limite serve a non appesantire la pagina,
nell'export non avrebbe senso.

Verificare inoltre che le colonne del file coincidano con quelle della tabella:
severità, tipo, tecnico, giorno, ore, righe, commesse coinvolte, rilievo,
dettaglio.

## 4. Nota sulle colonne a video

La tabella mostra ora anche **Tipo** e **Commesse**, che prima comparivano solo
nell'export. Non è un dato nuovo: è lo stesso dato reso visibile anche a schermo,
perché video ed export riportino le stesse informazioni.

## 5. Rollback

Ripristinare `dgb_activities.php` dalla copia precedente, poi:

```sql
UPDATE app_settings SET setting_value='1.8.55'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Stop + Start Apache, Ctrl+F5.
