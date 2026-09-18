# Manuale Amministratore — v1.8.60

## Ogni linea ha la sua base di costo

Il costo aziendale non si calcola allo stesso modo per tutte le linee. La
classificazione che avete fornito è ora nel sistema:

| Base | Linee |
|---|---|
| costo direzionale | WTS-GES, WTS-SOC |
| full cost | NV_AI, NV_DT, NV_EVENTI, NV_FI, NV_GC, NV_GS, NV_TI, WTS-AM, WTS-HD, WTS-PRES |
| fascia interna o costo direzionale | NV_SC, WTS-ACM, WTS-CC, WTS-CSS, WTS-MEG, WTS-MON, WTS-REP, WTS-SD |

## Il full cost c'era, il costo direzionale no

**Full cost**: l'ho trovato in anagrafica operatori del gestionale. 202 operatori
su 256 lo hanno valorizzato, in media 25.040 € annui. È un costo **annuo**: viene
diviso per 1.760 ore lavorabili (8 ore × 220 giorni) ottenendo circa **18,03
€/ora** di media.

Da notare: le fasce interne vanno da 31,25 a 68,75 €/ora, il full cost medio è
18,03. Sono grandezze diverse — il full cost è il costo pieno della persona, la
fascia è una tariffa interna — e non sono interscambiabili. Usare l'una al posto
dell'altra sposta il margine in modo sistematico.

**Costo direzionale**: non c'è. L'ho cercato in tutte le 102 tabelle del dump,
non esiste alcuna colonna che lo contenga.

Ho predisposto la struttura e classificato le linee, ma non l'ho stimato: WTS-GES
e WTS-SOC valgono insieme circa due milioni, e un margine costruito su un costo
inventato verrebbe usato per prendere decisioni.

Quando avrete il dato:

```sql
UPDATE cm_operator_costs SET directional_cost_hour = <valore>
 WHERE source_id = <id operatore>;
```

## Dove siamo con la copertura

```sql
SELECT costo_origine, SUM(prestazioni), ROUND(SUM(ore))
  FROM v_cm_copertura_costi GROUP BY costo_origine;
```

| Origine | Ore |
|---|---|
| **rilevato dal gestionale** | 317.960 (94%) |
| né full cost né fascia | 16.959 |
| nessuna base | 3.010 |
| né costo direzionale né fascia | 944 |

Il gestionale fornisce già il costo per il 94% delle ore, e quel valore **non
viene mai sostituito** da una stima, per quanto raffinata sia la base prevista.
La base di costo interviene solo sul restante 6%.

Restano **20.913 ore scoperte**: nessuna delle basi riesce a valorizzarle.

## Una terza tabella duplicata nel gestionale

`dgb_operator` ha 512 righe per 256 operatori: ogni riga compare due volte. È il
terzo caso dopo le allocazioni (v1.8.57).

La ricorrenza suggerisce che sia una caratteristica di come viene prodotto quel
dump, non un incidente. L'import usa `DISTINCT`, ma vale la pena verificarlo su
ogni nuova tabella: il controllo è immediato e ha già intercettato due difetti.

## WTS-SOC e WTS-AM

Comparivano nel vostro elenco, quindi ora hanno una base di costo. Il **modello
contrattuale** resta però da definire: sono due informazioni indipendenti.
Restano `da_classificare` sul lato ricavo.
