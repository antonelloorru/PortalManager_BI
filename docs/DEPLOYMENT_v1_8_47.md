# Deployment — PortalManager v1.8.47

Da applicare all'installazione attualmente in produzione, **v1.8.46**.

## 1. Contenuto del pacchetto

```
VERSION                          1.8.47
manage_projects.php              (ROOT)  interfaccia rivista, filtri completi
app/ProjectModel.php             listAll() con 38 criteri e 14 ordinamenti
app/Version.php                  PM_VERSION = 1.8.47
sql/migration_v1_8_47.sql        8 indici + bump di versione
sql/upgrade_1_7_56_to_1_8_47.sql consolidato cumulativo (327 statement)
docs/                            questa documentazione
```

`manage_projects.php` nella radice del portale, gli altri due in `app\`.

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Sovrascrivere i tre file rispettando i percorsi.
3. SQL Runner: `sql/migration_v1_8_47.sql` (da v1.8.46) oppure
   `sql/upgrade_1_7_56_to_1_8_47.sql` (da versione precedente o incerta).
4. **Stop + Start Apache**.
5. **Ctrl+F5** — necessario: la pagina porta CSS nuovo, e una copia in cache
   mostrerebbe i pannelli senza stile.

## 3. Verifica post-deploy

| Passo | Esito atteso |
|---|---|
| Footer | `1.8.47` |
| Gestione Commesse → Commesse / Progetti | in alto barra strumenti, poi i pannelli chiusi, poi l'elenco |
| Pulsante **Nuova commessa** | apre il pannello di inserimento |
| Inserire senza codice e confermare | messaggio di errore **e pannello riaperto** |
| Titolo **Filtri di ricerca** | apre e chiude il pannello |
| Applicare un filtro | il pannello resta aperto e mostra "N attivi" |
| Ricaricare la pagina filtrata | il pannello si apre da solo |
| **Azzera tutti** | torna all'elenco completo, pannello chiuso |
| Filtro *in scadenza entro 60 giorni* | solo commesse con fine entro due mesi |
| Filtro *solo in perdita* | solo commesse con margine negativo |
| **Esporta XLSX** | file `lista_commesse_<timestamp>.xlsx`, apribile, 29 colonne |
| **Esporta CSV** | stesse colonne, separatore `;`, accenti corretti |
| Esportare **con un filtro attivo** | il file contiene solo le righe filtrate |

L'ultima riga è la più importante: l'export deve seguire i filtri, non l'elenco
completo.

## 4. Nota sugli indici

La migration aggiunge otto indici a `cm_projects`. Su un portafoglio di alcune
migliaia di righe l'operazione è rapida; su tabelle molto grandi conviene
eseguirla fuori orario, perché `ALTER TABLE` blocca le scritture per la durata.

## 5. Rollback

Ripristinare i tre file dalla copia precedente e riportare l'etichetta:

```sql
UPDATE app_settings SET setting_value='1.8.46'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Gli indici possono restare: non alterano il comportamento della versione
precedente. Per rimuoverli: `DROP INDEX idx_proj_end_date ON cm_projects;` e
analoghi.

Stop + Start Apache, Ctrl+F5.
