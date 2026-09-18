# Deployment — PortalManager v1.8.81

## 1. Contenuto

```
VERSION                          1.8.81
workload_overview.php            (ROOT)  serie assenze con toggle
dgb_activities.php               (ROOT)  container uniformato
app/DgbModel.php                 assenze filtrate per tecnico
app/Version.php                  PM_VERSION = 1.8.81
gli altri file                   invariati da v1.8.80
sql/migration_v1_8_81.sql        is_visit_candidate + 2 viste
sql/upgrade_1_7_56_to_1_8_81.sql consolidato cumulativo (559 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i quattro file rispettando i percorsi.
3. SQL Runner: `sql/migration_v1_8_81.sql` (da v1.8.80) oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

## 3. Verifica — Carico & Sovrapposizioni

Sotto il grafico dell'andamento del carico compare una legenda **ASSENZE** con
quattro pulsanti: Ferie, Permessi, Recupero ore, Visite.

| Controllo | Atteso |
|---|---|
| Cliccare un pulsante | la serie sparisce, il pulsante si attenua |
| Cliccare di nuovo | la serie riappare |
| Serie **Visite** | tratteggiata, non continua |
| Colori | distinti da quelli delle risorse |

Ogni pulsante riporta il totale ore della sua serie nel periodo.

## 4. Verifica — Distribuzione sulle 24 ore

**Senza filtro tecnico**: la banda delle assenze mostra il totale aziendale — su
luglio 2026, circa 1.660 h.

**Con un tecnico selezionato** nei filtri: la banda mostra solo le sue assenze.
Su un operatore di prova, 130,5 h su 19 giorni.

Se la banda non cambia selezionando un tecnico, verificare che il filtro
operatore della pagina sia effettivamente applicato.

## 5. Le visite sono trasversali

**Le ore della serie Visite sono già comprese** in ferie, permessi o recuperi:
non vanno sommate al totale.

Nel gestionale le visite non sono un tipo di impegno: chi le registra sceglie
permesso, recupero o ferie a seconda dei casi, e la causale resta nella
descrizione. La serie le riconosce da lì.

Su tutto il periodo: 173 impegni, 573 ore, distribuiti su quattro tipi diversi.

Se voleste renderle una categoria vera, servirebbe un tipo dedicato nel
gestionale — e la riclassificazione dei 173 impegni esistenti.

## 6. Rollback

```sql
DROP VIEW IF EXISTS v_cm_assenze_serie_giorno;
DROP VIEW IF EXISTS v_cm_assenze_serie;
ALTER TABLE cm_commitment_types DROP COLUMN IF EXISTS is_visit_candidate;
UPDATE app_settings SET setting_value='1.8.80'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Ripristinare anche i quattro file.
