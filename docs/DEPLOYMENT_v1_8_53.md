# Deployment — PortalManager v1.8.53

Release **solo applicativa** per i dati: nessuna riga viene modificata o
eliminata. La migration aggiunge parametri e tre viste.

## 1. Contenuto

```
VERSION                          1.8.53
dgb_activities.php               (ROOT)  legende, suggerimenti, export
app/DgbModel.php                 regola oraria nella distribuzione
app/Version.php                  PM_VERSION = 1.8.53
gli altri file                   invariati da v1.8.52
sql/migration_v1_8_53.sql        parametri orario + 3 viste
sql/upgrade_1_7_56_to_1_8_53.sql consolidato cumulativo (386 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i file rispettando i percorsi.
3. SQL Runner: `sql/migration_v1_8_53.sql` (da v1.8.52) oppure
   `sql/upgrade_1_7_56_to_1_8_53.sql`.
4. **Stop + Start Apache**.
5. **Ctrl+F5**.

## 3. Verifica post-deploy

Il controllo principale è la **quadratura**:

```sql
SELECT * FROM v_dgb_ore_check;
```

| Campo | Atteso |
|---|---|
| `ore_consuntivate` | invariato rispetto a prima dell'aggiornamento |
| `ore_ordinarie` + `ore_reperibilita` | uguale a `ore_consuntivate` |
| `scarto` | **0,00** |
| `pct_reperibilita` | circa 13,5% sui dati attuali |

Uno scarto diverso da zero indica un problema nella ripartizione: i totali non
sono affidabili finché non si indaga.

| Passo | Esito atteso |
|---|---|
| Footer | `1.8.53` |
| Attività & Rendicontazione DGB | legenda "ordinario / reperibilità" |
| Passare il mouse su una barra | riporta "reperibilità" invece di "straordinario" |
| Export XLSX/CSV | colonna "Reperibilità (h)" |

### Che cosa cambia nei numeri

**Le ore totali non cambiano.** Cambia la ripartizione: la parte arancione del
grafico cresce sensibilmente, perché la reperibilità passa dalle 5.299 ore
dichiarate alle circa 44.300 effettive.

Chi confronta con report precedenti troverà quindi molta più reperibilità. È
l'effetto voluto: prima veniva conteggiata come ordinaria anche l'attività svolta
di sabato o alle 21:00.

## 4. Da fare dopo l'aggiornamento: censire i turnisti

Chi opera in turni non è soggetto alla regola, ma **nessun turnista risulta
attualmente censito**: i 146 profili configurati sono tutti "ordinario".

Finché non vengono classificati, le ore dei turnisti svolte fuori dalla fascia
09:00–18:00 risultano in reperibilità.

Per classificarli: **Attività & Rendicontazione DGB** → scheda **Incaricati** →
impostare `schedule_type` a "turni" per chi lavora su turni, oppure usare la
funzione di auto-classifica.

Dopo la classificazione i valori del grafico si aggiornano da soli: la regola è
applicata al momento della lettura, non memorizzata.

## 5. Se l'orario aziendale cambia

I parametri sono in `app_settings` (`work_ordinary_start`, `work_ordinary_end`,
`work_break_start`, `work_break_end`, `work_ordinary_days`,
`work_ordinary_hours`).

**Attenzione**: modificarli documenta la nuova regola ma non la applica da solo.
Gli orari sono cablati anche nell'espressione SQL delle viste e in
`DgbModel::FRAC_ORD`, per non costruire dinamicamente una query eseguita su
70.000 righe a ogni caricamento. Un cambio di orario richiede quindi una piccola
release che allinei le tre definizioni.

## 6. Rollback

Ripristinare `dgb_activities.php` e `app/DgbModel.php` dalla copia precedente,
poi:

```sql
DROP VIEW IF EXISTS v_dgb_ore_check;
DROP VIEW IF EXISTS v_dgb_ore_ripartite;
DROP VIEW IF EXISTS v_dgb_ore_classificate;
UPDATE app_settings SET setting_value='1.8.52'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Nessun dato è stato modificato: il rollback riporta semplicemente alla
classificazione dichiarata dalla sorgente.
