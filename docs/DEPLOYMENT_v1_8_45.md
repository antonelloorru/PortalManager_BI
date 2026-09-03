# Deployment — PortalManager v1.8.45

## 1. Contenuto del pacchetto

```
VERSION                              1.8.45
import_commesse_db.php               (ROOT)  connessione diretta + import CSV
import_employees_xlsx.php            (ROOT)  invariato da v1.8.43
app/SourceDb.php                     NUOVO   connessione read-only multi-driver
app/CommesseSync.php                 NUOVO   mappatura e sincronizzazione
app/ListFilter.php                           invariato da v1.8.44
app/EmployeeImportSchema.php                 invariato da v1.8.43
app/Version.php                              PM_VERSION = 1.8.45
sql/migration_v1_8_45.sql                    tabella cm_source_db
sql/upgrade_1_7_56_to_1_8_45.sql             consolidato cumulativo (320 statement)
docs/                                        questa documentazione
```

`SourceDb.php` e `CommesseSync.php` sono **file nuovi**: senza di essi
`import_commesse_db.php` non funziona.

## 2. Prerequisiti

**Driver PDO.** Serve l'estensione corrispondente al database del gestionale. Su
XAMPP `pdo_mysql` è attiva per impostazione predefinita; per SQL Server o
PostgreSQL occorre abilitare in `php.ini` la riga corrispondente e riavviare
Apache. La pagina elenca i driver disponibili e segnala l'assenza.

**Accesso di rete.** Il server che ospita il portale deve raggiungere host e
porta del gestionale: verificare eventuali firewall.

**Utenza sul gestionale.** Predisporre un'utenza **dedicata di sola lettura**,
con permesso `SELECT` sulla sola tabella `contract`. Il portale applica vincoli
propri, ma i privilegi restano la garanzia sostanziale.

**`.env.php`.** La cifratura usa `APP_SECRET`. Se il file venisse rigenerato, le
password già salvate non sarebbero più decifrabili e andrebbero reinserite.

## 3. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. I due file `import_*.php` nella radice; i cinque file in `app\`.
3. SQL Runner: `sql/migration_v1_8_45.sql` (da v1.8.44) oppure
   `sql/upgrade_1_7_56_to_1_8_45.sql` (da versione precedente o incerta).
4. **Stop + Start Apache**.
5. **Ctrl+F5**.

## 4. Configurazione

**Gestione Commesse → Import Commesse DB**, riquadro *Connessione diretta*.

| Campo | Note |
|---|---|
| Tipo database | solo i driver disponibili; imposta la porta predefinita |
| Indirizzo | host o IP del gestionale |
| Porta | 3306 MySQL, 1433 SQL Server, 5432 PostgreSQL |
| Nome database | database del gestionale |
| Utente / Password | utenza di sola lettura |
| Schema | opzionale, per esempio `dbo` su SQL Server |
| Tabella sorgente | `contract` salvo diversa indicazione |
| Timeout | secondi, da 3 a 60 |

Salvare, quindi procedere nell'ordine: **Prova connessione** → **Anteprima** →
**Sincronizza ora**.

## 5. Verifica post-deploy

| Passo | Esito atteso |
|---|---|
| Footer | `1.8.45` |
| Prova connessione | versione del server e colonne riconosciute, senza mancanti |
| Anteprima | tabella con le prime righe e l'azione prevista per ciascuna |
| Conteggio commesse dopo l'anteprima | invariato: l'anteprima non scrive |
| Sincronizza ora | riepilogo con righe lette, nuove, aggiornate |
| Commesse / Progetti | codici e valori allineati al gestionale |
| Rieseguire la sincronizzazione | nessun duplicato, solo aggiornamenti |

## 6. Se qualcosa non va

**"L'estensione PDO per … non è caricata"** — abilitare il driver in `php.ini` e
riavviare Apache.

**Timeout in connessione** — host, porta o firewall. Verificare la raggiungibilità
dal server del portale, non dalla propria postazione.

**"Colonne obbligatorie assenti"** — tabella o schema errati: la tabella indicata
non è quella dei contratti.

**"Sulla sorgente sono ammesse solo istruzioni SELECT"** — protezione
intenzionale: la sincronizzazione non scrive mai sul gestionale.

## 7. Rollback

Ripristinare i file dalla copia precedente e riportare l'etichetta:

```sql
UPDATE app_settings SET setting_value='1.8.44'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

La tabella `cm_source_db` può restare: è inerte senza i file che la usano. Per
rimuovere le credenziali: `DROP TABLE cm_source_db;`.
