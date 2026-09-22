# Release Checklist — PortalManager v1.8.68

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.67.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.68` |
| `dgb_activities.php` | ROOT, **modificato** | OK |
| `app/Version.php` | modificato | OK |
| 5 ROOT + 6 in `app/` | invariati da v1.8.67 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.68**
- [x] Release solo applicativa: nessuna variazione di schema né di dati

## 2. Segnalazione 1 — distinzione visiva

- [x] La v1.8.67 usava **una sola tinta**: la natura stava nel grassetto delle
      etichette, che non si legge scorrendo la griglia
- [x] Separate **tinta** (natura) e **intensità** (volume)
- [x] Blu `#2563eb` e arancione `#f59e0b`: **gli stessi colori delle barre** del
      grafico sopra, così i due si leggono con un solo codice
- [x] Entrambe le scale partono da un grigio neutro: una cella con poche ore resta
      leggibile come tale, la natura emerge quando c'è qualcosa da vedere
- [x] Legenda con quadratini e **totali per natura** sopra la matrice
- [x] Il suggerimento della cella riporta anche la natura

## 3. Coerenza con la regola della v1.8.53

- [x] La condizione include il **giorno**, non solo l'ora:
      `($ord && !$weekend)`
- [x] Una cella delle 10:00 di sabato è **reperibilità**: colorarla di blu
      contraddirebbe la classificazione usata per i calcoli
- [x] Colonne del fine settimana interamente arancioni: **è la regola resa
      visibile**, non un effetto da correggere
- [x] I totali per natura usano la **medesima condizione** del colore: se
      divergessero, uno dei due sarebbe sbagliato

## 4. Segnalazione 2 — verificata prima di intervenire

| Vista | Bucket | Ordinario | Reperibilità |
|---|---|---|---|
| `month` | 1 | 9.595,7 | 1.827,8 |
| `day` | **31** | 9.595,7 | 1.827,8 |

- [x] Il grafico **si aggiornava già**
- [x] Porzioni arancioni: altezza mediana **16,4 px** su 150, **0** sotto la
      soglia dei 2 px oltre la quale una banda sparisce
- [x] Il difetto reale era il **titolo identico** nelle due viste: senza conferma,
      l'impressione è che non sia successo nulla
- [x] Il titolo dichiara ora periodo, numero di barre e totale ore
- [x] Correggere il grafico avrebbe risolto un problema inesistente lasciando
      quello vero

## 5. QA — quadratura fra i due grafici

| | Ordinario | Reperibilità | Totale | Quota rep. |
|---|---|---|---|---|
| Grafico a colonne | 9.595,7 | 1.827,8 | 11.423,5 | **16,0%** |
| Matrice oraria | 9.568,1 | 1.709,0 | 11.277,1 | **15,2%** |

- [x] Scarto **146,4 h (1,3%)**: interventi a cavallo della mezzanotte, esclusi
      dalla matrice
- [x] La quota di reperibilità resta allineata entro 0,8 punti
- [x] L'esclusione toglie proporzionalmente più reperibilità che ordinario,
      perché riguarda interventi in gran parte notturni
- [x] Divergenza **misurata e documentata** in deployment e manuale: senza, il
      primo confronto fra i totali la scambia per un difetto

## 6. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_demo` | 4 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_demo` | 4 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_demo` | 4 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c68` **collation mista** | 482 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c68` | 482 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c68` | 482 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Consolidato verificato sullo scenario a collation mista della v1.8.66
- [x] Conteggio statement consolidato: **481 → 482**

## 7. Nota di metodo

Delle due segnalazioni, una era un difetto e l'altra no.

Misurare prima di correggere ha evitato di modificare un grafico che funzionava —
e ha fatto emergere il difetto vero, che era la mancanza di un riscontro nel
titolo. Se avessi accettato la diagnosi implicita nella segnalazione, avrei
cambiato il grafico lasciando l'utente senza conferma di quale vista stesse
guardando.

## 8. Aperto

- La matrice esclude gli interventi a cavallo della mezzanotte (1,3% delle ore).
  Includerli richiede di spezzarli su due giorni: fattibile, ma va deciso se
  l'1,3% giustifichi la complessità.
- Restano i punti della v1.8.63: collegamento fra divisione e tecnico, ed esame
  delle 137 commesse segnalate solo dal gestionale.
