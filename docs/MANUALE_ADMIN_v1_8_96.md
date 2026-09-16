# Manuale Amministratore — v1.8.96

## I cinque indicatori richiesti

| KPI | Valore |
|---|---|
| **Valore economico complessivo** | **7.022.269 €** — 48 commesse, 28 aperte |
| **Headcount** | **35 persone** di presidio |
| **Giornate di copertura** | **4.820** da personale non di presidio |
| **Dettaglio commesse** | ricavo, costo interno, margine % |
| **Allocazione** | 20 fisse, 5 con sostituzioni, 16 in rotazione |

Costo interno 3.479.331 €, margine 3.542.938 €.

## La regola sull'esclusività: una precisazione necessaria

Avete indicato che il personale di presidio «non registra interventi su commesse
diverse dalla propria». Applicata alla lettera, questa regola escluderebbe persone
che sono chiaramente presidi:

| Persona | Ore su presidio | Ore totali | Quota | Commesse |
|---|---|---|---|---|
| Balestrieri Paolo | 6.896 | 6.915 | **99,7%** | 2 |
| Senesi Alessio | 5.632 | 5.632 | 100% | 1 |

**Balestrieri ha 19 ore fuori dal presidio in tutta la sua storia.** Escluderlo
perché «compare su due commesse» sarebbe formalmente corretto e sostanzialmente
sbagliato.

Ho quindi usato una **soglia sulla quota di ore: l'80%**. La distribuzione
giustifica la scelta:

| Quota | Persone |
|---|---|
| 95–100% | 28 |
| 80–95% | 7 |
| 50–80% | 10 |
| sotto 50% | 36 |

C'è una separazione netta fra chi sta sopra il 95% e chi sta sotto il 50%, e l'80%
cade in una zona poco popolata.

**È una convenzione, non un dato**: se la vostra prassi usa un criterio diverso, la
soglia si cambia in `app_settings` senza una release.

## Le quattro classificazioni

Ho tenuto **distinti** i due criteri — unità organizzativa e quota di ore — perché
insieme dicono più che sommati:

| Classificazione | Significato |
|---|---|
| **presidio confermato** | nell'unità e sopra soglia |
| **presidio di fatto** | ne fa le ore ma **non è assegnato all'unità** |
| **assegnato non operante** | è nell'unità ma **non ne fa le ore** |
| **copertura** | sotto soglia, fuori unità |

Le ultime due sono **segnalazioni utili**: la prima indica un'assegnazione
mancante, la seconda un'anagrafica da aggiornare o una persona che ha cambiato
ruolo.

Sul database di prova risultano 35 «di fatto» e 0 «confermati» perché nessun
profilo ha l'unità assegnata. **Sul vostro server, dove le assegnazioni ci sono,
vedrete la ripartizione vera.**

## Fissa o rotazione: conto le ore, non le persone

Contare le persone sarebbe stato immediato e fuorviante.

**WTS_3043**: sette persone e 666 giornate di copertura. Contandole è «rotazione».
Ma Passiatore copre il **78,5%** delle ore e gli altri sei si dividono il resto —
è un presidio fisso con sostituzioni, ed è così che l'ho classificata.

| Tipo | Criterio |
|---|---|
| **fissa** | la persona principale copre almeno l'80% |
| **fissa con sostituzioni** | fra il 60% e l'80% |
| **rotazione** | nessuno arriva al 60% |

## Una domanda che vi devo porre

Le «giornate di copertura» si possono contare in due modi, e danno numeri diversi:

| Metodo | Risultato |
|---|---|
| Giorni di calendario in cui una persona non di presidio ha lavorato | **4.820** |
| Ore di copertura diviso 8 | **1.656** |

Una sostituzione di due ore occupa un giorno di calendario ma vale un quarto di
giornata. **Quale delle due è quella che vi serve dipende da come viene
fatturata**: espongo entrambe e mi dite quale mettere in evidenza.

## Cosa manca

La **pagina** con filtri, grafici, export e stampa. Le viste sono verificate e
rispondono a tutti e cinque i KPI; la pagina si costruisce su queste.
