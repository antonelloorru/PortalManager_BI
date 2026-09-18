# Deployment — PortalManager v1.9.1

## 1. Contenuto

```
VERSION                          1.9.1
app/Version.php                  PM_VERSION = 1.9.1
gli altri file                   invariati da v1.9.0
sql/migration_v1_9_1.sql         2 viste
sql/upgrade_1_7_56_to_1_9_1.sql  consolidato cumulativo
docs/                            questa documentazione
```

**Prerequisito**: v1.9.0 applicata.

**Nessuna vista esistente viene modificata**: questa release aggiunge due viste
nuove e non tocca ciò che i pannelli usano oggi.

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare `app/Version.php`.
3. SQL Runner: `sql/migration_v1_9_1.sql` (da v1.9.0) oppure il consolidato.
4. **Stop + Start Apache**.

## 3. Guardate prima i numeri

```sql
SELECT tipo_contratto, formula, commesse,
       ROUND(margine_attuale) AS attuale,
       ROUND(margine_formula) AS corretto,
       ROUND(scarto) AS scarto, scarto_pct
  FROM v_cm_margine_scostamento
 WHERE ABS(scarto) > 1 ORDER BY ABS(scarto) DESC;
```

Attesi sui vostri dati:

| Tipo | Attuale | Corretto | Scarto |
|---|---|---|---|
| **Presidio** | 3.763.893 | 9.575.010 | **+5.811.117** |
| **Contr. Servizi Scalare** | 4.299.557 | 2.419.780 | **−1.879.778** |
| Servizio Gestito SOC | 1.174.630 | 2.571.672 | +1.397.042 |

**Totale: da 22.519.194 a 27.876.216, +5,36 milioni.**

## 4. La singola commessa

```sql
SELECT commessa, cliente, tipo_contratto, formula,
       valore_oggi, valore_consuntivato, costi_consuntivati,
       margine_attuale, margine, scarto_formula, margine_pct
  FROM v_cm_margine_formula
 WHERE ABS(scarto_formula) > 10000
 ORDER BY ABS(scarto_formula) DESC LIMIT 30;
```

Le colonne `margine_attuale` e `margine` sono affiancate: potete verificare
commessa per commessa da dove viene la differenza prima di decidere.

## 5. Perché non ho sostituito i valori

Le viste che alimentano i pannelli — `v_cm_redditivita_commessa`,
`v_cm_dir_commessa` e le altre — **continuano a calcolare come prima**.

Sostituirle in questa release avrebbe fatto cambiare i numeri di tutti i cruscotti
da un giorno all'altro, e reso impossibile riconciliare un report stampato la
settimana precedente.

Quando avrete verificato lo scostamento e deciso di adottarlo, il passaggio è una
modifica circoscritta che posso preparare.

## 6. Come leggere le due direzioni

**Presidio, +154%**: il valore consuntivato oggi viene sottratto, la formula C dice
che va sommato. Il margine reale è più alto di quanto il portale mostri.

**Contratti a Scalare, −44%**: oggi si usa il valore contrattato, la formula D dice
che conta il consuntivato. Il margine reale è più basso.

Le due direzioni non si compensano per caso: sono due errori opposti su tipi
contrattuali diversi.

## 7. Il margine percentuale

Su un contratto a scalare `margine_pct` usa il **consuntivato** come
denominatore, non il plafond. Le altre formule usano il valore a oggi.

Una percentuale calcolata sul denominatore sbagliato sarebbe stata coerente e
priva di significato.

## 8. Gli storni

Il documento cita «costi consuntivati **e storni**» come un'unica grandezza, e
`cm_projects.actual_cost` è il consuntivo che il gestionale ha già consolidato.

**Se gli storni fossero esposti in una colonna separata**, andrebbero sottratti
esplicitamente: ditemi dove si trovano e aggiorno le viste.

## 9. Rollback

```sql
DROP VIEW IF EXISTS v_cm_margine_scostamento;
DROP VIEW IF EXISTS v_cm_margine_formula;
UPDATE app_settings SET setting_value='1.9.0'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Nessun altro effetto: le viste esistenti non sono state toccate.
