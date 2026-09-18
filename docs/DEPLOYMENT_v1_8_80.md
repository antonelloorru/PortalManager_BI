# Deployment — PortalManager v1.8.80

Release **solo applicativa**: nessuna variazione di schema né di dati.

## 1. Contenuto

```
VERSION                          1.8.80
dgb_activities.php               (ROOT)  riquadro Quadro del periodo
app/DgbModel.php                 + periodSummary()
app/Version.php                  PM_VERSION = 1.8.80
gli altri file                   invariati da v1.8.79
sql/migration_v1_8_80.sql        solo bump di versione
sql/upgrade_1_7_56_to_1_8_80.sql consolidato cumulativo (553 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i tre file rispettando i percorsi.
3. SQL Runner: `sql/migration_v1_8_80.sql` (da v1.8.79) oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

## 3. Verifica

**Attività & Rendicontazione DGB**, in testa: riquadro **Quadro del periodo**.

| Elemento | Contenuto |
|---|---|
| Prima riga | giorni lavorativi, ore al giorno, incaricati, capacità, consuntivate |
| **Dettaglio delle ore** | otto voci con ore e quota sul consuntivo |

**Il controllo che conta**: la *capacità ordinaria* del riquadro deve coincidere
con la somma della linea di riferimento nel grafico sottostante. Su luglio 2026
sono entrambe **10.544,0 h**.

Passando il mouse sulla capacità compare anche quella teorica a organico pieno e
la presenza media.

## 4. Come leggere il dettaglio

**Le otto voci si sovrappongono.** Un intervento da remoto durante un turno di
reperibilità conta sia in *da remoto* sia in *in reperibilità*. Sommarle darebbe
un totale superiore alle ore lavorate.

Solo **in orario** e **fuori orario** formano una partizione: sommano esattamente
alle ore consuntivate.

## 5. L'avviso sulle extra

Se compare l'avviso *«Extra dichiarate e ore fuori orario non coincidono»*, è
perché le extra dichiarate sul modulo sono meno della metà delle ore calcolate
fuori orario.

Non è un errore del portale: sono due misure dello stesso fenomeno, una
dichiarata alla fonte e una calcolata dagli orari. Se devono corrispondere, la
differenza indica che molte ore fuori orario non vengono dichiarate come extra
sul gestionale.

Sui dati attuali: 5.411 h dichiarate contro 45.181 h calcolate.

## 6. Rollback

Ripristinare i tre file dalla copia precedente, poi:

```sql
UPDATE app_settings SET setting_value='1.8.79'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
