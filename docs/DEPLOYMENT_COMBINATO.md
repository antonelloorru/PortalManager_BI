# Deployment — PortalManager v1.9.16 + v1.9.17

Pacchetto **combinato**: il codice è quello della v1.9.17, che comprende già
tutte le modifiche della v1.9.16.

## 1. Perché un pacchetto solo

Le due release toccano gli stessi file. La v1.9.17 corregge anche un difetto
introdotto dalla v1.9.16 — `$mo->costiQuadro()` dove il modello si chiama `$it` —
quindi **applicare solo la .16 lascerebbe quel difetto attivo**.

I file applicativi sono già alla v1.9.17. Le due migration restano distinte perché
l'una crea strutture che l'altra presuppone.

## 2. Ordine di applicazione

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i file:

```
service_desk.php            (ROOT)
it_service.php              (ROOT)
app/it_service_print.php
app/Version.php
```

3. SQL Runner, **in quest'ordine**:

```
sql/migration_v1_9_16.sql     ← prima
sql/migration_v1_9_17.sql     ← poi
```

Oppure, in alternativa a entrambe:

```
sql/upgrade_1_7_56_to_1_9_17.sql    ← consolidato, 746 statement
```

4. **Stop + Start Apache**, **Ctrl+F5**.

## 3. L'ordine conta

`migration_v1_9_16.sql` crea `cm_um_fasce` e `cm_um_tempi`, e ridefinisce
`v_cm_sd_costi_valorizzati` per leggere le tariffe reali.

`migration_v1_9_17.sql` è solo un allineamento di versione: non dipende dalla
prima per funzionare, ma applicarla da sola lascerebbe il portale con le tariffe
dedotte e la versione dichiarata sbagliata.

Il **consolidato** le contiene entrambe nell'ordine giusto: se avete dubbi, usate
quello.

## 4. Cosa cambia — v1.9.16

**Le tariffe vengono dal listino contrattuale** invece che dedotte da un template.

`cm_contract_rates` era già sincronizzata con 35.335 righe su 1.173 commesse: non
serve risincronizzare.

**Sei fasce** (A–X) invece di due, lette da `dgb_forms_activity.id_activitytype`
invece che dedotte dall'orario.

## 5. Cosa cambia — v1.9.17

**Il riepilogo costi compare anche nel report personale** del Service Desk, e la
Relazione di Servizio IT riconosce il caso personale.

**Correzione**: `$mo` → `$it` in due file. Se avete già applicato la v1.9.16 e il
riquadro costi della Relazione IT non compariva, era questo.

## 6. Verifica dopo l'aggiornamento

```sql
SELECT setting_value FROM app_settings WHERE setting_key = 'app_version';
-- atteso: 1.9.17
```

```sql
SELECT fascia, um, tipo, valorizzate, copertura_pct FROM v_cm_rate_disponibili
 WHERE fascia = 'C' ORDER BY um;
```

Attesa copertura fra il **59,5% e il 65,1%** secondo l'unità.

**Service Desk** → selezionare un componente → **Report personale**: deve
contenere il riepilogo costi.

**Relazione IT** → selezionare **un solo incaricato**: il pulsante diventa «Report
personale».

## 7. Rollback

Per tornare alla v1.9.15:

```sql
DROP VIEW IF EXISTS v_cm_rate_disponibili;
DROP TABLE IF EXISTS cm_um_tempi;
DROP TABLE IF EXISTS cm_um_fasce;
UPDATE app_settings SET setting_value='1.9.15'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Poi rieseguire `migration_v1_9_15.sql` e ripristinare i quattro file applicativi.

**`cm_contract_rates` non va toccata**: esisteva già prima della v1.9.16.
