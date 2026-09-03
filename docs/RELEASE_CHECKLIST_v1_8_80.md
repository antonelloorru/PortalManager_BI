# Release Checklist — PortalManager v1.8.80

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.79.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.80` |
| `dgb_activities.php` | ROOT, **modificato** | OK |
| `app/DgbModel.php` | **+ periodSummary()** | OK |
| `app/Version.php` | modificato | OK |
| 6 ROOT + 4 in `app/` | invariati da v1.8.79 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.80**
- [x] Release solo applicativa

## 2. La capacità coincide con la linea di riferimento

- [x] Primo calcolo `giorni × ore × incaricati`: **14.720 h**
- [x] Linea di riferimento dei grafici: **10.544 h**
- [x] **Due verità sulla stessa grandezza** — corretto usando la stessa formula
- [x] La linea (v1.8.52) somma per giorno gli operatori **attivi quel giorno**:
      non tutti gli 80 lavorano tutti i 23 giorni, e il totale periodo gonfia
      del 40%

| Verifica | Esito |
|---|---|
| Capacità nel quadro | **10.544,0 h** |
| Linea di riferimento | **10.544,0 h** |
| **Coincidenza** | **esatta** |

- [x] Capacità **teorica** esposta come confronto nel suggerimento: 14.720 h
- [x] **Presenza media** derivata: 71,6% — informazione che nessun'altra vista dà

## 3. QA — quadro del periodo (luglio 2026)

| Voce | Valore |
|---|---|
| Giorni lavorativi | 23 |
| Ore al giorno | 8 |
| Incaricati | 80 |
| Capacità ordinaria | 10.544,0 h |
| Ore consuntivate | 9.464,5 h |
| **Utilizzo** | **89,8%** |

## 4. QA — dettaglio delle ore

| Voce | Ore | Quota |
|---|---|---|
| In orario ordinario | 8.017,1 | 84,7% |
| Fuori orario ordinario | 1.447,4 | 15,3% |
| Extra dichiarate | 200,5 | 2,1% |
| In reperibilità | 55,0 | 0,6% |
| Da remoto | 1.550,5 | 16,4% |
| Smart working | 465,0 | 4,9% |
| Ore di viaggio | 286,5 | 3,0% |
| Da recuperare | 79,0 | 0,8% |

- [x] **Quadratura**: in orario + fuori orario = ore consuntivate — verificata
- [x] Fuori orario **per differenza**: nessuno scarto di arrotondamento
- [x] Le voci si sovrappongono e sono esposte come **quote del consuntivo**, non
      come segmenti: presentarle come parti di una torta implicherebbe che
      sommino al totale

## 5. Extra dichiarate contro fuori orario calcolate

| | Tutto il periodo | Luglio 2026 |
|---|---|---|
| Extra dichiarate | 5.411,0 h | 200,5 h |
| Fuori orario calcolate | **45.181,5 h** | **1.447,4 h** |

- [x] Fattore **otto** di divergenza
- [x] Mostrate **entrambe**: nascondere una delle due eliminerebbe l'informazione
      più interessante
- [x] Avviso solo sotto il 50%: uno scostamento fisiologico non deve fare rumore
- [x] Dichiarato che è un dato sul **processo di registrazione**, non un difetto
      del portale

## 6. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_demo` | 4 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_demo` | 4 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_demo` | 4 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c80` fresco | 553 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c80` | 553 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c80` | 553 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Conteggio statement consolidato: **552 → 553**

## 7. Nota di metodo

Due misure divergenti nella stessa pagina sono peggio di una misura imprecisa:
chi legge non sa quale usare, e la fiducia in entrambe cade.

Qui si è scelto quale è **la** capacità — quella già usata dai grafici — e
l'altra è stata esposta come confronto dichiarato. Lo stesso trattamento per
extra e fuori orario, dove però la scelta non era possibile perché nessuna delle
due è sbagliata: sono misure di cose diverse che dovrebbero coincidere e non
coincidono.

## 8. Aperto

- **Le extra dichiarate coprono l'11% delle ore fuori orario.** Se la regola
  aziendale è che ogni ora fuori orario sia extra, il dato del gestionale è
  incompleto e va corretto alla fonte. Se invece "extra" ha un significato più
  ristretto — solo lo straordinario autorizzato — le due misure non devono
  coincidere e l'avviso va disattivato.
- I giorni lavorativi non escludono le **festività**: un periodo che comprenda
  Ferragosto o Natale sovrastima leggermente la capacità teorica. La capacità
  ordinaria non ne risente, perché si basa sugli operatori effettivamente attivi.
