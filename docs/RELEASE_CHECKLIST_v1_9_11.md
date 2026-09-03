# Release Checklist — PortalManager v1.9.11

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.9.10.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.9.11` |
| `service_desk.php` | ROOT, **modificato** | OK |
| `app/SdModel.php` | **+ 6 metodi** | OK |
| `app/Version.php` | modificato | OK |
| 30 file restanti | invariati da v1.9.10 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.9.11**

## 2. Il raccordo

- [x] Il **modulo** porta `project_id`, il ticket no: era la chiave mancante
- [x] Errore di partenza mio: ho cercato nella tabella dei ticket perché
      l'obiettivo parlava di ticket, invece di chiedermi **quale tabella
      contenesse la stessa attività con più informazioni**
- [x] Stessa forma dell'errore v1.9.2 su `margin_total`

## 3. Chi è il Service Desk

- [x] **Unità organizzativa**, criterio dichiarato: 4 profili su «Service Desk»
- [x] Preferibile alla soglia della v1.8.96: l'appartenenza è una decisione
      aziendale, la soglia era un'interpretazione
- [x] Join su `nome` **e** `employee_id`: i moduli scrivono il nome come testo e
      il collegamento all'anagrafica può mancare
- [x] Unità come **elenco** in `app_settings`

## 4. Fatturabile o interna

- [x] Dalla **natura della commessa** (`has_revenue`), già popolata: nessuna
      regola nuova, nessuna seconda definizione divergente
- [x] `COALESCE(has_revenue, 1)`: le linee non classificate contano come
      fatturabili — contarle interne gonfierebbe il costo non addebitato

## 5. Le tariffe NULL

- [x] `DEFAULT NULL`, non zero: zero significherebbe «gratis»
- [x] Con zero il valore a listino sarebbe `0,00` su tutte le righe — **un numero
      che si somma e sembra un dato**
- [x] **Verificato**: rimossa la tariffa di ACM, `valore_listino` → NULL, non 0
- [x] Il pannello segnala quante linee sono senza tariffa

## 6. «Interventi» e non «ticket»

- [x] Un ticket può generare più moduli, un modulo coprire più ticket
- [x] Chiamarli «ticket» avrebbe prodotto un numero che **non torna con quello
      della sezione ticket**, senza che nessuno sappia perché

## 7. QA — sei quadrature

| Verifica | Esito |
|---|---|
| Ore fatturabili = quadro | **18,00 = 18,00** |
| Ore interne = quadro | **8,00 = 8,00** |
| Fatturabili + interne = totale | **26,00 = 26,00** |
| Ore per tecnico = totale | **26,00 = 26,00** |
| Valore addebitato = quadro | **1.200,00 = 1.200,00** |
| Valore a listino = quadro | **1.465,00 = 1.465,00** |

| Controllo | Esito |
|---|---|
| Tecnici in elenco senza attività | **4 su 4** |
| Filtro periodo (1990) | 0 interventi |
| Filtro tecnico | 10,00 < 26,00 h |
| Tariffa assente → NULL | **OK** |
| Metodi inesistenti | **0** |
| `<div>` in stampa | **78 = 78** |
| Avvisi PHP | **0** |

## 8. QA SQL

| Test | DB | Esito |
|---|---|---|
| Migration RUN1/RUN2/RUN3 | `pm_real` | 13 stmt, **err=0** |
| Coda consolidato RUN1/RUN2 | `pm_real` | 11 stmt, **err=0** |

- [x] Un `;` in un commento intercettato e corretto
- [x] `grep -c '^[[:space:]]*--.*;'` = **0**

## 9. Difetti dell'ambiente intercettati

- [x] `cm_projects.name` non ha valore predefinito: l'inserimento di prova
      falliva
- [x] `company_cost_import` **non ammette NULL**: usato zero, che la vista tratta
      già come «non addebitato» — è il motivo per cui la vista espone il valore
      solo se `> 0`

## 10. Aperto

- **Le tariffe di listino sono da compilare**: nascono a NULL e finché lo sono il
  valore a listino non viene calcolato. Nessuna delle fonti disponibili contiene
  un listino.
- **I moduli sono vuoti nel dump**: il collaudo usa 5 moduli costruiti su 4
  commesse, poi rimossi. Le quadrature sono verificate, i numeri reali si vedranno
  in produzione.
- **OBJ_2 resta sui ticket** per la parte gestiti/escalati: quella è
  correttamente una misura di ticket, non di moduli.
- Restano gli aperti precedenti: viste dei pannelli su `value_total - actual_cost`,
  `workload_overview` e `dgb_activities` non uniformati.
