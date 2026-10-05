# MANUALE AMMINISTRATORE — Progetti PRJ (v1.10.03)

## 1. Permessi (Gestione permessi → Gestione Commesse)
| Voce | Pagina/permesso | Azioni |
|---|---|---|
| Progetti PRJ (elenco) | manage_projects_prj.php (virtuale) | view, create (nuovo, clona), export |
| ↳ Scheda progetto PRJ | prj_dashboard.php | view, edit, export (XLSX/DOCX) |
| ↳ Calcolo scenari PRJ | prj_dashboard_calc.php (virtuale) | edit = calcoli salvati e aggiornamento consuntivi |
| ↳ Collegamento PRJ - commessa SP | prj_link.php (virtuale) | edit = collegare/scollegare (anche dalla scheda commessa) |
| ↳ Costi reali dipendenti (PRJ) | prj_costs_real.php (virtuale) | view = costi reali nel confronto e negli export |
| Parametri dimensionamento | prj_parameters.php | view, edit |
| Scenari & confronti progetti | prj_history.php | view, export |

- Assegnazione iniziale: Super Admin, Resp. Commerciale, Direttore IT, Coordinatore Tecnico, Finance.
- Costi reali: ai ruoli che vedono la Compensation.
- Chi ha i permessi PRJ ma non le commesse SP raggiunge l'elenco da «Scenari & confronti progetti» o «Parametri dimensionamento».

## 2. Parametri globali
- **Contenuto**: oneri, indennità H24, ore e giorni per FTE, uplift, supporto sostenibile, margine obiettivo, postazione, energia, sedi; zone, nearshore, dotazioni, costi di sede, overhead.
- **Versioni**: ogni modifica ha una decorrenza. Il numero di versione apre lo storico e «×» dismette una voce da oggi.
- **Oneri di default**: se il parametro `oneri_pct` manca si usa il moltiplicatore costo pieno HR dell'anno − 1.

## 3. Collegamento e sincronizzazione
- **Dopo ogni sincronizzazione**:
  - i PRJ la cui commessa è stata eliminata diventano «non collegati», con evento «orfano da sync» e voce nel log;
  - le commesse che riportano un codice PRJ nel campo «commerciale» vengono collegate al PRJ.
- Il modulo non scrive mai le commesse.

## 4. Alert sugli scostamenti
- **Regole**: `prj_scost_fte` e `prj_scost_costo` in `cm_alert_rules`, create disattivate.
  - Attivarle con `is_active = 1`.
  - Soglie di default: attenzione 10%, allarme 20%, in valore assoluto.
- **Rilevazione**: sull'ultimo mese completo, dopo «Aggiorna consuntivi» (o al primo accesso alla tab), con il normale ciclo di AlertEngine.
- **Destinatario**: il commerciale della commessa (`cm_alert_recipients`).

## 5. Dati e manutenzione
- **Calcoli salvati**: non si modificano. Un progetto con calcoli salvati non è eliminabile: usare lo stato Ritirato o Chiuso.
- **Cestino**: tecnologie, assegnazioni e scenari eliminati sono recuperabili dal cestino.
- **Audit**: write_log (categoria Commesse) ed EntityChangeLog. Lo storico è nella tab Storico della scheda.
- **Verifiche**:
  - `tools/verify_v1_9_99.php` … `tools/verify_v1_10_03.php`, una per release;
  - `tools/verify_v1_10_00.php` ricalcola i valori attesi della gara ASPI.
