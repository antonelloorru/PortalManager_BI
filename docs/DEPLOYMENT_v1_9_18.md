# Deployment — PortalManager v1.9.18

## 1. Contenuto

```
VERSION                           1.9.18
app/Version.php                   PM_VERSION = 1.9.18
gli altri file                    invariati da v1.9.17
sql/migration_v1_9_18.sql         5 viste + 2 parametri
sql/upgrade_1_7_56_to_1_9_18.sql  consolidato cumulativo (754 statement)
docs/                             questa documentazione
```

**Nessun file applicativo modificato**: la release aggiunge viste.

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare `app/Version.php`.
3. SQL Runner: `sql/migration_v1_9_18.sql` (da v1.9.17) oppure il consolidato.
4. **Stop + Start Apache**.

## 3. Verifica

```sql
SELECT * FROM v_cm_it_giorni_quadro\G

SELECT operatore, giorni_lavorati, interventi, ore, giornate_equiv, ore_per_giorno,
       giorni_C, giorni_D, aree, commesse, produzione_teorica, produzione_per_giorno
  FROM v_cm_it_giorni_operatore ORDER BY ordina;

SELECT operatore, area_tecnologica, giorni, ore, quota_ore_pct, produzione_teorica
  FROM v_cm_it_giorni_area ORDER BY ordina, ore DESC;
```

## 4. «Giorni lavorati» sono giorni distinti

Un operatore con quattro interventi in tre giorni — due nello stesso giorno —
risulta con **3 giorni lavorati**, non 4.

Accanto c'è `giornate_equiv` = ore ÷ 8. **Le due misure divergono di proposito**:
chi lavora due ore al giorno per venti giorni ha 20 giorni lavorati e 5 giornate
equivalenti.

Se vi serve una misura sola, ditemi quale: la richiesta diceva «giorni lavorati» e
ho messo quella per prima.

## 5. Il conteggio per fascia

`giorni_C`, `giorni_D` e le altre contano **giorni distinti per fascia**.

**Un giorno con interventi in due fasce conta in entrambe**: la somma delle fasce
può superare i giorni totali, ed è corretto. Se vi servisse la ripartizione
esclusiva, servirebbe una regola per decidere a quale fascia attribuire un giorno
misto.

## 6. Il perimetro

```sql
SELECT setting_value FROM app_settings WHERE setting_key = 'it_giorni_linee_escluse';
```

L'elenco è **per esclusione**: una linea nuova entra automaticamente. Per
escluderne un'altra, aggiungetela al parametro.

Restano attive quattro linee: WTS-ACM, WTS-CSS, WTS-CC, WTS-MEG.

## 7. Il filtro sulle commesse attive cambia i numeri nel tempo

Un modulo su una commessa **oggi** chiusa non entra, anche se all'epoca era
aperta.

**Un report di marzo ristampato in settembre può quindi dare numeri diversi.** È
la lettura letterale della richiesta ed è quella giusta per la produzione
corrente, ma va saputo.

Per riconciliare:

```sql
SELECT operatore, giorni_lavorati AS totali, giorni_su_attive, giorni_su_chiuse,
       ore, ore_su_attive
  FROM v_cm_it_giorni_tutte ORDER BY ordina;
```

## 8. La produzione è teorica

Ore × tariffa di listino: **ciò che il lavoro varrebbe**, non ciò che è stato
fatturato.

`righe_senza_tariffa` dice quante righe non hanno listino. Se è alto, la
produzione è parziale — controllate la copertura:

```sql
SELECT fascia, um, valorizzate, copertura_pct FROM v_cm_rate_disponibili
 WHERE fascia IN ('C','D') ORDER BY fascia, um;
```

## 9. Una cosa da guardare

```sql
SELECT fascia_letta_pct FROM v_cm_it_giorni_quadro;
```

È la quota di moduli la cui fascia è **letta** dall'attività invece che dedotta
dall'orario. Se è bassa, le fasce sono in gran parte supposte e il conteggio per
fascia eredita quell'incertezza.

## 10. Rollback

```sql
DROP VIEW IF EXISTS v_cm_it_giorni_quadro;
DROP VIEW IF EXISTS v_cm_it_giorni_area;
DROP VIEW IF EXISTS v_cm_it_giorni_tutte;
DROP VIEW IF EXISTS v_cm_it_giorni_operatore;
DROP VIEW IF EXISTS v_cm_it_giorni_base;
DELETE FROM app_settings
 WHERE setting_key IN ('it_giorni_linee_escluse','it_giorni_solo_attive');
UPDATE app_settings SET setting_value='1.9.17'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
