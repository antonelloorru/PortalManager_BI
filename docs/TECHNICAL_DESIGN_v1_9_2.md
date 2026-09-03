# Technical Design — PortalManager v1.9.2

## 1. L'errore che ho commesso

Il documento diceva «Valore a oggi più valore consuntivato meno costi
consuntivati e storni». L'ho letto come un'espressione algebrica e l'ho tradotta
in `value_total + value_todate - actual_cost`.

Il foglio di esempi mostra che il margine totale è `A - G`, cioè valore meno
costi, e che le altre voci — ricavi maturati, da maturare, FY in corso — sono
**orizzonti temporali** dello stesso calcolo, non addendi.

«Più valore consuntivato» descriveva la struttura del prospetto, non la formula.

## 2. L'errore a monte, che è più istruttivo

Ho ricostruito un calcolo senza prima cercare se il risultato esistesse già.

`cm_projects.margin_total` e `margin_todate` sono popolati su tutte e 1.092 le
commesse. Erano lì da sempre: la v1.8.94 usava `value_total - actual_cost` per il
report direzionale, e nessuno — me compreso — aveva verificato se il gestionale
fornisse il margine già calcolato.

La verifica costa una query. Il ricalcolo è costato una release sbagliata e un
numero comunicato con sicurezza — «+5,36 milioni» — che era falso.

**Prima di derivare un valore, controllare se qualcuno lo ha già derivato.** È lo
stesso errore che avevo evitato nella v1.8.93 con `exec_company_id`, e ripetuto
qui.

## 3. Perché la formula D era invece giusta

Sui Contratti a Scalare la mia formula D dava −1.879.777 di scarto rispetto alla
ricostruzione ingenua; il gestionale ne dà −1.870.326.

I due numeri coincidono a meno di 9.451 su 168 commesse. La formula era corretta —
il che conferma che il problema non era la lettura del documento in generale, ma
quella specifica riga.

162 commesse su 168 divergono dalla ricostruzione: su questo tipo contrattuale
`value_total - actual_cost` è sistematicamente sbagliato.

## 4. La ricostruzione resta accanto

`margine_ricostruito` e `scarto_ricostruzione` non servono al calcolo: servono a
misurare quanto una scorciatoia plausibile si discosta dal valore vero.

Le viste esistenti — `v_cm_dir_commessa`, `v_cm_redditivita_commessa` — usano
tuttora `value_total - actual_cost`. Sapere che quella differenza vale 1,3 milioni
su 194 commesse è la premessa per decidere se sostituirle.

## 5. La tabella di riferimento non diventa inutile

Si potrebbe pensare che, leggendo il margine dal gestionale, sapere quale formula
usa non serva più.

Serve per tre cose: spiegare perché due commesse con valori simili hanno margini
diversi, accorgersi se un tipo cambiasse regola, e sapere quale base di costo si
applica — informazione che serve al lavoro sulla redditività a costo reale della
v1.8.97, dove il full cost si applica solo a 10 linee su 20.

## 6. `v_cm_voci_calcolo` come dizionario

Una vista che non calcola nulla: associa i nomi del foglio di esempi — «Margine
maturato (P)» — alle colonne del portale.

Sembra ridondante e non lo è: ogni volta che qualcuno chiede «dov'è il margine
maturato» la mappa va ricostruita a memoria, e questa release nasce proprio da una
mappa ricostruita male.
