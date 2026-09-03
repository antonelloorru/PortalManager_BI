# Release Checklist — PortalManager v1.8.90

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.89.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.90` |
| `it_service.php` | ROOT, **NUOVO** | OK |
| `app/it_service_print.php` | **NUOVO** | OK |
| `app/MenuManager.php` | modificato — voce di menu | OK |
| `app/Router.php` | modificato — slug | OK |
| `app/Version.php` | modificato | OK |
| 8 ROOT + 7 in `app/` | invariati da v1.8.89 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.90**
- [x] `it_service` in `Router::PAGES` per lo slug opaco

## 2. La sezione sui dati reali (2026)

| Indicatore | Valore |
|---|---|
| Interventi | 16.855 |
| Giornate-uomo | **9.401** |
| Ore | 71.835,0 — **7,6 h/giornata** |
| Ore a ricavo | 53.015,5 (**73,8%**) |
| Ore di viaggio | 2.188,5 |
| Km | 0 (copertura 0%) |

- [x] 472 righe di dettaglio, 7 mesi di andamento
- [x] Grafici: 5 modalità, 10 linee, 1 settore (dipende dalle assegnazioni),
      3 durate, 2 fasce

## 3. Filtri e raggruppamento

- [x] Sette dimensioni a **selezione multipla** più la natura della commessa
- [x] Semantica: OR dentro la dimensione, AND fra dimensioni
- [x] Verificato: 3 linee → 3.937 interventi; 3 linee **AND** da remoto → 613
- [x] Filtro combinato: 4.831 ≤ 16.855 — sottoinsieme contenuto
- [x] `gb` validato contro **elenco chiuso**: finisce in un `GROUP BY`

## 4. Grafici

- [x] Barre **orizzontali**: le etichette sono nomi di persone e linee di
      servizio, che ruotati o troncati diventano illeggibili
- [x] Funzione definita **una volta**, usata a video e in stampa: cinque grafici,
      due contesti, un punto di correzione
- [x] Andamento a barre **impilate**: la quota fuori orario si legge nella
      proporzione, senza confrontare due grafici

## 5. Export con pivot

- [x] Sei fogli; il pivot è **107 incaricati × 19 linee = 2.033 celle**
- [x] Costruito nell'export e non a video: a doppia entrata è illeggibile in
      pagina, in Excel è la forma su cui si fa un grafico pivot
- [x] Celle vuote e **non zero**: 1.900 zeri nasconderebbero le celle piene e
      schiaccerebbero gli assi di un grafico costruito sopra

## 6. Stampa a colori

- [x] **A4 orizzontale**: la tabella ha 14 colonne più quelle del raggruppamento
- [x] Il report **riceve il contesto** dalla pagina invece di ricostruirlo:
      pannello e stampa non possono divergere
- [x] Filtri applicati riportati in testa al foglio
- [x] `print-color-adjust: exact` più la nota che resta una **preferenza del
      browser** non forzabile dal server
- [x] Precisato che gli **SVG escono a colori comunque** — sono vettori, non
      sfondi: senza l'opzione scompaiono solo le aree piene dei riquadri KPI
- [x] Salto di pagina esplicito prima del dettaglio
- [x] `page-break-inside: avoid` contro il titolo orfano

## 7. QA — quadrature e template

| Verifica | Esito |
|---|---|
| Somma per modalità = totale ore | **71.835,0 = 71.835,0** |
| Somma per durata = interventi | **16.855 = 16.855** |
| Somma del pivot = totale ore | **71.835,0 = 71.835,0** |
| Barre oltre la larghezza | **0** |
| Fuori orario > ore totali | **0** |
| Metodi statici inesistenti | **0** |
| Metodi `ItServiceModel` inesistenti | **0** |
| Avvisi o errori PHP | **0** |

- [x] Il collaudo esegue **le espressioni del template**: larghezze delle barre,
      altezze dell'andamento, costruzione del pivot (metodo v1.8.86)
- [x] La quadratura del pivot intercetta gli errori di `GROUP BY`: chiave
      incompleta → righe moltiplicate; join sbagliato → righe perse

## 8. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_demo` | 4 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_demo` | 4 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_demo` | 4 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c90` fresco | 596 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c90` | 596 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c90` | 596 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Conteggio statement consolidato: **595 → 596**

## 9. Aperto

- **I chilometri sono a zero**: servono gli indirizzi di sedi e clienti. La pagina
  lo dichiara con un avviso e mostra le ore di viaggio come misura alternativa.
  Il servizio di geocodifica non è incluso: va scelto il fornitore.
- **Il settore tecnologico dipende dalle assegnazioni**: 27 profili su 104 hanno
  l'unità. Le ripartizioni per settore diventano significative man mano che
  vengono completate.
- La stampa dei colori resta soggetta alla preferenza del browser.
