# Deployment — PortalManager v1.8.89

**Release di fondamenta**: viste e modello di lettura. La pagina non è inclusa.

## 1. Contenuto

```
VERSION                          1.8.89
app/ItServiceModel.php           NUOVO — letture della relazione di servizio
app/Version.php                  PM_VERSION = 1.8.89
gli altri file                   invariati da v1.8.88
sql/migration_v1_8_89.sql        cm_it_distances + 3 viste + lat/lng su clients
sql/upgrade_1_7_56_to_1_8_89.sql consolidato cumulativo (595 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i due file in `app\`.
3. SQL Runner: `sql/migration_v1_8_89.sql` (da v1.8.88) oppure il consolidato.
4. **Stop + Start Apache**.

## 3. Verifica

```sql
SELECT COUNT(*) AS righe, COUNT(DISTINCT incaricato) AS incaricati,
       COUNT(DISTINCT linea_servizio) AS linee, COUNT(DISTINCT settore) AS settori,
       ROUND(SUM(ore),1) AS ore
  FROM v_cm_it_servizio;
```

Attesi circa 67.700 righe, 145 incaricati, 21 linee.

**Il numero di settori dipende dalle vostre assegnazioni**: sul database di prova
è 1 perché nessun profilo ha l'unità organizzativa. Sul vostro server ce ne sono
27 assegnati, quindi i settori saranno quelli.

```sql
SELECT modalita, COUNT(*), ROUND(SUM(ore),1) FROM v_cm_it_servizio GROUP BY modalita;
```

Attese cinque modalità: in sede, da remoto, presso cliente, smart working,
reperibilità.

## 4. I chilometri

**Oggi la copertura è zero.** Per attivarla servono tre passi.

**Primo — gli indirizzi.** Eseguire la sincronizzazione della v1.8.74, che importa
gli indirizzi dei clienti da `forms_company`. Le sedi hanno indirizzo solo in 10
casi su 169: vanno completate a mano in anagrafica.

**Secondo — la lista di lavoro.**

```sql
SELECT * FROM v_cm_it_distanze_mancanti ORDER BY interventi DESC LIMIT 50;
```

Elenca le coppie sede-cliente che compaiono nei moduli e non hanno distanza,
ordinate per peso: le prime cinquanta coprono la maggior parte degli interventi.

**Terzo — popolare le distanze.** A mano:

```sql
INSERT INTO cm_it_distances (location_id, client_id, km_one_way, source)
VALUES (3, 118, 42.5, 'manuale');
```

Oppure con geocodifica, che richiede una chiave API di un servizio di mappe e uno
script che percorra la lista. Non è incluso in questa release: va deciso quale
servizio usare.

**I km restano NULL finché non ci sono**, non zero: la vista li espone come
mancanti e l'indicatore di copertura lo segnala.

## 5. Nel frattempo, le ore di viaggio

Sono disponibili subito e misurano lo stesso fenomeno:

```sql
SELECT ROUND(SUM(ore_viaggio),1) FROM v_cm_it_servizio;
```

Attese circa 10.100 ore.

## 6. Rollback

```sql
DROP VIEW IF EXISTS v_cm_it_scheda;
DROP VIEW IF EXISTS v_cm_it_servizio;
DROP VIEW IF EXISTS v_cm_it_distanze_mancanti;
DROP TABLE IF EXISTS cm_it_distances;
ALTER TABLE clients DROP COLUMN IF EXISTS lat, DROP COLUMN IF EXISTS lng,
                    DROP COLUMN IF EXISTS geocoded_at;
UPDATE app_settings SET setting_value='1.8.88'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
