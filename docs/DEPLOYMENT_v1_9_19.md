# Deployment — PortalManager v1.9.19

## 1. Contenuto

```
VERSION                           1.9.19
it_service.php                    (ROOT)  riquadro + export
app/ItServiceModel.php            + 5 metodi
app/it_service_print.php          riquadro nei due report
app/Version.php                   PM_VERSION = 1.9.19
gli altri file                    invariati da v1.9.18
sql/migration_v1_9_19.sql         solo bump di versione
sql/upgrade_1_7_56_to_1_9_19.sql  consolidato cumulativo (755 statement)
docs/                             questa documentazione
```

**Prerequisito**: v1.9.18 applicata (le viste `v_cm_it_giorni_*`).

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare **`it_service.php` in ROOT** e i tre file in `app\`.
3. SQL Runner: `sql/migration_v1_9_19.sql` oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

## 3. Verifica

**Relazione di Servizio IT** → riquadro **Giorni lavorati per persona**.

Per ogni operatore: giorni lavorati, giornate equivalenti, ore per giorno, giorni
in fascia C e D, produzione teorica, e le aree tecnologiche come etichette
colorate.

**Report di stampa**: sia il generale sia il personale contengono il riquadro.

**Export XLSX**: tre fogli nuovi.

## 4. Le due avvertenze automatiche

Compaiono **solo quando servono**:

**«Solo il N% dei moduli ha la fascia letta dall'attività»** — sotto il 50%. Il
conteggio per fascia eredita quell'incertezza: i giorni in fascia D potrebbero
essere in parte supposti.

**«N interventi senza tariffa di listino»** — la produzione teorica è parziale.

Se le vedete, i numeri sono utilizzabili ma non definitivi.

## 5. Il blocco di riconciliazione

Compare **solo se esistono operatori con giorni su commesse oggi chiuse**.

Serve a rispondere a «perché il totale è cambiato»: il filtro guarda lo stato
della commessa **oggi**, non alla data dell'intervento. Un report ristampato dopo
la chiusura di una commessa dà numeri più bassi senza che nulla sia cambiato nei
moduli.

Se non lo vedete, non c'è nulla da riconciliare.

## 6. Le aree colorate

Ogni area tecnologica ha una tinta **stabile**: la stessa area ha lo stesso colore
in tutte le righe.

Passando il puntatore su un'etichetta si leggono giorni e ore di quell'area.

## 7. Rollback

Ripristinare `it_service.php`, `app/ItServiceModel.php` e
`app/it_service_print.php` dalla v1.9.18.

```sql
UPDATE app_settings SET setting_value='1.9.18'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Le viste `v_cm_it_giorni_*` restano: sono della v1.9.18.
