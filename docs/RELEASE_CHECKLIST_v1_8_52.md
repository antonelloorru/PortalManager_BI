# Release Checklist — PortalManager v1.8.52

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.51.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.52` |
| `dgb_activities.php` | ROOT, **modificato** | OK |
| `app/DgbModel.php` | **modificato** | OK |
| `app/Version.php` | modificato | OK |
| altri 6 file ROOT + 7 in `app/` | invariati da v1.8.51 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.52**, verificato su `pm_c52`
- [x] Release solo applicativa: nessuna variazione di schema né di dati

## 2. Diagnosi riprodotta sui dati reali

Le tre parti della segnalazione hanno cause distinte, tutte misurate su giugno 2023.

| Sintomo | Causa |
|---|---|
| "il grafico è un monolite" | nessun drill-down dal mese al giorno |
| "non vedo il dettaglio giornaliero" | esisteva ma richiedeva due passaggi non suggeriti |
| "non vedo la linea di riferimento" | riferimento sovrastimato del 70% e azzerato nei weekend |

## 3. QA — riferimento giornaliero

| Verifica | Prima | Dopo |
|---|---|---|
| Riferimento giornaliero | 448 h fisse (56 incaricati del periodo) | 232–264 h (29–33 attivi) |
| Scarto medio dalle ore reali | **258,52 h** | **8,52 h** |
| Aderenza | — | **97% migliore** |
| Utilizzo nei feriali | ~55% apparente | **92–101%** |
| Mediana degli attivi (giorni senza dati) | non prevista | 31 incaricati |
| Fine settimana con ore e riferimento | 0 su 5 | **5 su 5** |

- [x] Mediana e non media: giornate di chiusura o presidio minimo distorcerebbero
      la media
- [x] I punti stimati sono marcati `estimated` ed esportati come "stimato"

## 4. QA — grafico generato dal file di release

Funzione `dgb_dist_svg()` **estratta dal sorgente** ed eseguita.

| Verifica | Esito |
|---|---|
| Vista mensile: barre cliccabili | **12 su 12 bucket** |
| Primo collegamento | `…&gran=day&month=2023-01` |
| Suggerimento "clic per il dettaglio" | presente |
| Descrizioni `<title>` | 36 (area + ordinario + straordinario) |
| Vista giornaliera: collegamenti | **0** — nessun livello sotto |
| Linea di riferimento spezzata | **4 segmenti** — uno per settimana lavorativa |
| Percentuale di utilizzo nei suggerimenti | presente |
| Export SVG senza drill | **0 collegamenti** |
| SVG ben formato (parsing XML) | mensile **SI**, giornaliero **SI** |

- [x] Area cliccabile a tutta altezza: nei mesi con poche ore la sola barra
      sarebbe un bersaglio impraticabile
- [x] Il drill-down preserva i filtri attivi tramite `$qs()`
- [x] Punto isolato reso come cerchietto: una polilinea di un solo punto non
      disegnerebbe nulla

## 5. QA — SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_lite` | 4 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_lite` | 4 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_lite` | 4 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c52` fresco (132 tabelle) | 380 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c52` | 380 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c52` | 380 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Conteggio statement consolidato: **379 → 380**

## 6. Coerenza analitica

- [x] Numeratore e denominatore del rapporto di utilizzo riguardano ora la stessa
      popolazione: ore di chi ha lavorato / capacità di chi ha lavorato
- [x] La percentuale di utilizzo compare nel suggerimento della singola barra,
      dove riguarda un bucket ed è corretta, **non fra le colonne esportate**,
      dove inviterebbe a essere sommata: è un rapporto, non è additivo
- [x] L'export riporta ore e incaricati attivi, entrambi additivi, con cui il
      rapporto si ricalcola a qualunque livello
- [x] Distinzione fra "valore zero" e "valore assente" applicata coerentemente
      alla linea e ai fine settimana

## 7. Compatibilità

- [x] I **totali delle ore non cambiano**: le barre riportano gli stessi valori
- [x] Le percentuali della **vista mensile non cambiano**: lì il calcolo era già
      corretto
- [x] Cambiano le percentuali della vista giornaliera, ed è l'effetto voluto.
      Documentato nel deployment e nel manuale utente perché chi le avesse
      annotate non le creda un errore
- [x] Il parametro di drill-down è opzionale: gli export SVG restano privi di
      collegamenti

## 8. Documentazione

- [x] `CHANGELOG.md` — tre difetti distinti, con le misure
- [x] `TECHNICAL_DESIGN_v1_8_52.md` — denominatore sbagliato, mediana contro
      media, interruzione della linea, drill-down, non additività del rapporto
- [x] `DEPLOYMENT_v1_8_52.md` — verifica, avvertenza sui numeri che cambiano
- [x] `MANUALE_ADMIN_v1_8_52.md`, `MANUALE_UTENTE_v1_8_52.md`
- [x] `RELEASE_CHECKLIST_v1_8_52.md` — questo documento

## 9. Aperto, dichiarato

- Il riferimento si basa su chi ha **consuntivato** ore, non su chi era
  pianificato in servizio: è la migliore approssimazione disponibile con i dati
  presenti. Un tecnico presente ma senza ore registrate non entra nel
  denominatore, quindi l'utilizzo può risultare leggermente sovrastimato. Con una
  fonte di presenze o turni il riferimento diventerebbe esatto — richiede però un
  dato che oggi il portale non ha.
- Il drill-down scende di un livello, dal mese al giorno. Un ulteriore livello —
  dal giorno alle singole attività — sarebbe la prosecuzione naturale, ma
  richiede di decidere quale vista aprire e con quali filtri, e va concordato.
