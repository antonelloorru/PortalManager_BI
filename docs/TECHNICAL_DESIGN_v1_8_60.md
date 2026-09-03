# Technical Design — PortalManager v1.8.60

## 1. Costo e ricavo hanno regimi indipendenti

La v1.8.58 aveva introdotto il modello di **ricavo** per linea. Questa introduce
la base di **costo**, e le due non coincidono.

WTS-PRES è presidio sul lato ricavo e full cost sul lato costo. WTS-MON è canone
sul ricavo e fascia-o-direzionale sul costo. Sono due classificazioni ortogonali
sulla stessa entità, e vanno modellate come due attributi, non come uno.

Da qui `cost_basis` accanto a `revenue_basis` sulla stessa tabella, senza tentare
di derivare l'uno dall'altro.

## 2. Il full cost è annuo, non orario

`dgb_operator.full_cost` contiene valori fra 0 e 109.886: sono costi **annui**.

La conversione richiede le ore lavorabili annue, che sono una convenzione
aziendale e non un dato: 1.760 ore corrispondono a 8 ore per 220 giorni, coerenti
con l'orario ordinario definito nella v1.8.53.

Il parametro sta in `app_settings` perché è una convenzione rivedibile, e il
valore derivato è materializzato in `full_cost_hour` anziché calcolato a ogni
lettura: una divisione su 67.000 righe a ogni query è spreco, e il valore cambia
solo quando cambia il full cost.

Media risultante 18,03 €/ora, contro i 31,25–68,75 delle fasce. La differenza è
attesa — il full cost include il costo pieno della persona mentre la fascia è una
tariffa interna — ma va tenuta presente: le due basi non sono interscambiabili, e
usare l'una al posto dell'altra sposta il margine in modo sistematico.

## 3. Il costo direzionale: assente, dichiarato

Ricerca su tutte le 102 tabelle del dump per colonne contenenti *full*, *direz*,
*overhead*, *struttura*: tre risultati, tutti relativi al full cost. Il costo
direzionale non è nel dato.

Tre strade erano possibili:

1. stimarlo, per esempio come percentuale del costo tecnico;
2. ignorare le linee che lo richiedono;
3. predisporre la struttura e dichiarare l'assenza.

La prima produce un numero che sembra misurato. Su WTS-GES e WTS-SOC, che valgono
insieme due milioni, un margine costruito su un costo inventato è peggio di
nessun margine: verrebbe usato per decidere.

La seconda perde l'informazione che quelle linee esistono e quanto pesano.

La terza è quella adottata: `directional_cost_hour` esiste ed è NULL,
`costo_origine` dice *«SCOPERTO: né costo direzionale né fascia»*, e 944 ore
restano visibilmente non valorizzate. Quando il dato arriverà, basterà popolarlo.

## 4. Un'etichetta che mentiva

La prima stesura della vista produceva:

```
RIPIEGO su fascia: full cost operatore non disponibile | 16.959 ore | costo 0
```

Contraddittoria: se il ripiego sulla fascia fosse avvenuto, il costo non sarebbe
zero. In realtà mancavano entrambi, e l'etichetta descriveva un ripiego che non
era possibile.

È il tipo di difetto che sopravvive a lungo: il numero è plausibile, l'etichetta
è plausibile, e solo il loro accostamento rivela l'incoerenza. Chi leggesse solo
l'etichetta concluderebbe che il costo è stimato; chi leggesse solo il totale
concluderebbe che quelle ore non costano nulla.

Corretto condizionando il ripiego alla effettiva disponibilità della fascia, e
introducendo l'etichetta **SCOPERTO** per i casi senza alcuna base.

## 5. Precedenza: rilevato prima di tutto

```sql
CASE
    WHEN company_cost_import <> 0 THEN company_cost_import   -- rilevato
    WHEN cost_basis = 'full_cost'   AND full_cost_hour   IS NOT NULL THEN ore * full_cost_hour
    WHEN cost_basis = 'direzionale' AND directional_hour IS NOT NULL THEN ore * directional_hour
    WHEN hourly_cost IS NOT NULL THEN ore * hourly_cost      -- ripiego
    ELSE NULL
END
```

Il costo consuntivato dal gestionale non viene mai sostituito da una stima, per
quanto la base prevista sia più raffinata. Sui dati questo copre il 94% delle ore:
la base di costo interviene solo sul restante 6%.

È un ordine che vale la pena esplicitare, perché la tentazione opposta —
ricalcolare tutto con la base "giusta" per uniformità — sostituirebbe misure con
stime su 317.960 ore.

## 6. Terza tabella duplicata

`dgb_operator`: 512 righe, 256 id distinti. Dopo
`dgb_operator_allocations_on_forms_contract` (v1.8.57) è la terza tabella del
gestionale priva di chiave primaria e con righe ripetute.

La ricorrenza suggerisce che non sia un incidente ma una caratteristica del modo
in cui quel dump viene prodotto. Vale la pena verificarlo su ogni nuova tabella
importata: il controllo `COUNT(*)` contro `COUNT(DISTINCT id)` è immediato e ha
già intercettato due difetti.
