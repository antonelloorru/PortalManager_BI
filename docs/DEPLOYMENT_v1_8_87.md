# Deployment — PortalManager v1.8.87

## 1. Contenuto

```
VERSION                          1.8.87
service_desk.php                 (ROOT)  code, moduli, contratti
app/SdModel.php                  + 4 metodi
app/Version.php                  PM_VERSION = 1.8.87
gli altri file                   invariati da v1.8.86
sql/migration_v1_8_87.sql        4 viste
sql/upgrade_1_7_56_to_1_8_87.sql consolidato cumulativo (587 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare **`service_desk.php` in ROOT** e i due file in `app\`.
3. SQL Runner: `sql/migration_v1_8_87.sql` (da v1.8.86) oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

Nessuna risincronizzazione: le viste leggono i dati già presenti.

## 3. Verifica

La tabella d'insieme ha ora **due metà**: TICKET e MODULI DI INTERVENTO.

| Componente | Presi | Moduli | Ore | A ricavo |
|---|---|---|---|---|
| Enrico Mancini | 613 | 1.070 | 1.702,0 h | 34,4% |
| Sebastiano Chiarini | 520 | 1.130 | 1.402,0 h | 36,2% |
| Emanuele Bressi | 278 | 1.219 | **6.565,5 h** | 28,4% |
| Greta Ferrante | 49 | 648 | 2.239,0 h | 10,8% |

Nella scheda del singolo: **Moduli di intervento nel periodo** con cinque
indicatori, barra a ricavo/interne, e tabella per tipologia di contratto.

Se un componente non ha moduli nel periodo, compare una riga che lo dice invece
di una tabella vuota.

## 4. Ticket e moduli non si sommano

Un ticket può generare un modulo di intervento: sommarli conterebbe lo stesso
lavoro due volte.

Sono affiancati e separati visivamente. **Chi ha meno ticket può avere più ore
consuntivate**: Bressi ha il minor numero di ticket presi in carico e il maggior
numero di ore.

## 5. La quota sulla coda

La colonna **Quota coda** dice quale percentuale dei ticket di quella coda ha
toccato. Distingue il presidio dal transito: 5 ticket su 800 e 5 su 6 sono numeri
uguali con significati opposti.

Verde sopra il 40%, ambra sopra il 15%.

## 6. Le ore su commesse interne

Oltre due terzi delle ore dei componenti sono su commesse **senza ricavo** —
attività interne, help desk interno.

È un dato sulla **struttura del servizio**, non sul rendimento: chi lavora su
commesse interne non produce ricavo per ragioni che non dipendono da lui.

## 7. Se le ore risultassero zero

Verificare il ponte fra i nomi:

```sql
SELECT * FROM v_cm_sd_nome_moduli;
```

Attese quattro righe. Nei ticket il nome è `Nome Cognome`, nei moduli
`Cognome Nome`: la vista concilia entrambi gli ordini. Se una riga manca, quel
componente ha un nome scritto diversamente nelle due fonti.

## 8. Rollback

```sql
DROP VIEW IF EXISTS v_cm_sd_coda_dettaglio;
DROP VIEW IF EXISTS v_cm_sd_operativita;
DROP VIEW IF EXISTS v_cm_sd_moduli_contratto;
DROP VIEW IF EXISTS v_cm_sd_nome_moduli;
UPDATE app_settings SET setting_value='1.8.86'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Ripristinare `service_desk.php` e `app/SdModel.php` dalla v1.8.86.
