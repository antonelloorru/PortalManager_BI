# Deployment — PortalManager v1.8.68

Release **solo applicativa**: nessuna variazione di schema né di dati.

## 1. Contenuto

```
VERSION                          1.8.68
dgb_activities.php               (ROOT)  matrice a due colori, titolo con periodo
app/Version.php                  PM_VERSION = 1.8.68
gli altri file                   invariati da v1.8.67
sql/migration_v1_8_68.sql        solo bump di versione
sql/upgrade_1_7_56_to_1_8_68.sql consolidato cumulativo (482 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare `dgb_activities.php` in ROOT e `app/Version.php` in `app\`.
3. SQL Runner: `sql/migration_v1_8_68.sql` (da v1.8.67) oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

## 3. Verifica post-deploy

**Attività & Rendicontazione DGB** → **Giorni (mese)**.

| Controllo | Atteso |
|---|---|
| Titolo del grafico a colonne | riporta *«giorni di \<mese> \<anno> · N barre · ore»* |
| Passando a **Mesi (periodo)** | il titolo cambia in *«mesi del periodo»* |
| Sopra la matrice | legenda con quadratino **blu** e **arancione** e i totali per natura |
| Celle in fascia 09–13 e 14–18 nei feriali | **blu** |
| Tutte le altre celle | **arancione** |
| Colonne di sabato e domenica | arancioni **per intera** |
| Passando il mouse su una cella | giorno, ora, ore e natura |

Le colonne del fine settimana interamente arancioni sono corrette: la regola
della v1.8.53 stabilisce che sono ordinarie le fasce 09–13 e 14–18 **dal lunedì
al venerdì**, quindi nel fine settimana anche quelle ore sono reperibilità.

## 4. I due grafici non danno lo stesso totale

È atteso. Su marzo 2026:

| | Ordinario | Reperibilità | Totale |
|---|---|---|---|
| Grafico a colonne | 9.595,7 | 1.827,8 | 11.423,5 |
| Matrice oraria | 9.568,1 | 1.709,0 | 11.277,1 |

La differenza — 146,4 ore, l'**1,3%** — sono gli interventi a cavallo della
mezzanotte, che la matrice esclude perché richiederebbero di essere spezzati su
due giorni.

La quota di reperibilità resta allineata: 16,0% contro 15,2%.

## 5. Nota sul grafico a colonne

Il grafico **si aggiornava già** passando a *Giorni (mese)*: 31 barre con
ordinario e reperibilità corretti, porzioni arancioni alte in mediana 16 pixel.

Mancava l'indicazione nel titolo, identico nelle due viste: l'unico modo di
accorgersi del cambio era contare le barre. Ora il titolo lo dichiara.

## 6. Rollback

Ripristinare `dgb_activities.php` dalla copia precedente, poi:

```sql
UPDATE app_settings SET setting_value='1.8.67'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
