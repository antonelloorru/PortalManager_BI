# TECHNICAL DESIGN — v1.10.15 · Report Direzionale per tipologia

## Flusso
```
dir_report.php ?tab=acm|css|cc|meg|nv|moduli  (+ filtro principale, esito, toll)
   └─ DirModel::normFilters / whereSql   → perimetro commesse (v_cm_dir_commessa c ⨝ cm_projects p)
   └─ DirTipologie::build(tab)           → PmReport (kpi, bars, stacked, table, note)
         ├─ toHtml(false)  vista a schermo
         ├─ toHtml(true)   stampa (print=1)
         └─ send(docx|xlsx|csv|pdf)       (rep=…, permesso export)
```

## Sorgenti
| Dato | Sorgente |
|---|---|
| Tipologia | `cm_projects.service_line`: WTS-ACM, WTS-CSS, WTS-CC, WTS-MEG, `NV\_%` |
| Commerciale, cliente, date, valore, costo del lavoro | `v_cm_dir_commessa` (`agente`, `cliente`, `start_date`, `end_date`, `valore`, `costo`) |
| Descrizione | `cm_projects.description`, altrimenti la denominazione |
| Moduli, ore, fascia, listino, addebitato | `v_cm_it_giorni_base` (`ore`, `fascia`, `produzione_teorica` — valorizzata se non NULL —, `valore_addebitato`) |
| Ordini cliente / fatturato CC | `cm_project_operations` con `op_type_code = 'COR'` (`revenue`, `op_date`) |
| Costo totale MEG | `cm_projects.value_total − margin_total` (gestionale); minimo = costo del lavoro |

## Logiche di calcolo
| Scheda | Formula |
|---|---|
| ACM | listino = Σ produzione_teorica (giorno ≤ A); giornate = ore valorizzate / 8; tariffa €/gg = listino / giornate; giornate vendute = venduto / tariffa €/gg; consumo % = listino / venduto × 100; performance % = 100 − consumo %; esito con tolleranza ±toll |
| CSS / CC | periodo = Da–A, altrimenti l'anno corrente; una riga per commessa e anno; giorni uomo = COUNT DISTINCT(tecnico, giorno); giornate equivalenti = ore / 8 |
| CC | fatturato = Σ revenue degli ordini COR nell'anno; flag = esiste almeno un ordine COR per la commessa |
| MEG | margine = venduto − costo totale; margine % = margine / venduto |
| NV_ | incidenza % = ore della tipologia / ore di tutti i moduli delle commesse del perimetro nel periodo |
| Moduli | per tecnico × fascia: COUNT moduli, Σ ore, COUNT DISTINCT giorni, ore / 8 |

## Impostazioni e permessi
`app_settings.dir.acm_tolleranza_pct` (predefinito 5) può essere modificata per la singola richiesta con il parametro `toll` (0-50).
Vista e stampa richiedono `view` su `dir_report.php`; i file richiedono `export`. Nessuna modifica di schema.
