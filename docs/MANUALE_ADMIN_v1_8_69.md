# Manuale Amministratore — v1.8.69

## Ferie e permessi: avevo sbagliato

Nella risposta precedente vi avevo detto che ferie e permessi non erano nei dati.
Avevate ragione voi: sono in `forms_commitment`.

Non li avevo trovati perché li cercavo fra i **nomi** di colonne e tabelle,
mentre sono **valori** del campo `type`. È lo stesso errore che avevo fatto con
il costo direzionale, che era un valore di `forms_contract_operation_type`.

| Tipo | Righe | Ore | Operatori |
|---|---|---|---|
| Ferie | 3.298 | 26.186,0 | 94 |
| Permessi | 833 | 3.442,5 | 79 |
| Recupero ore | 658 | 2.884,0 | 38 |
| Malattia | 361 | 2.851,5 | 45 |
| Promemoria | 1.366 | 7.881,0 | 52 |
| Riunioni, patrono | 27 | 131,5 | — |

**Promemoria e riunioni non sono conteggiati come assenze**: occupano il
calendario ma sono tempo lavorato. Senza questa distinzione l'assenteismo
risulterebbe gonfiato di quasi 8.000 ore.

Per cambiare la classificazione di un tipo:

```sql
UPDATE cm_commitment_types SET is_absence = 0 WHERE code = 'SAINT_PATRON';
```

## La matrice distingue quattro nature

| Natura | Colore | Marzo 2026 |
|---|---|---|
| cliente · ordinario | blu | 7.058,0 h (62,6%) |
| cliente · reperibilità | arancione | 1.260,7 h (11,2%) |
| **interno · ordinario** | verde | **2.510,3 h (22,3%)** |
| **interno · reperibilità** | rosso | **448,4 h (4,0%)** |

Il dato che salta all'occhio: **oltre un quarto del tempo lavorato è attività
interna**, e il 22,3% è in orario ordinario. Non genera ricavo, ed è ora visibile
senza doverlo calcolare.

Quando una cella contiene più nature prende il colore di quella prevalente; il
suggerimento riporta la ripartizione completa.

## Le assenze stanno sotto, non dentro

Ferie, permessi, recuperi e malattia sono in una banda separata sotto la griglia,
con una riga per tipo.

Non sono distribuite sulle 24 ore perché **sono ore non lavorate**: metterle in
una fascia oraria darebbe l'impressione che qualcuno lavorasse durante le ferie.

Per lo stesso motivo il loro totale **non si somma** a quello del lavorato: sono
due grandezze diverse.

## Export XLSX

Il pulsante accanto alla matrice produce un file a **tre fogli**:

- **Celle giorno-ora** — ogni cella con giorno, ora, natura e ore
- **Profilo orario** — il totale per ciascuna delle 24 ore
- **Assenze** — giorno, tipo e ore

Sono i dati che generano il grafico, non un'immagine: servono a rifare i conti.
La quadratura è verificata: la somma delle celle esportate coincide con il totale
della matrice.

## Un difetto che ho corretto in collaudo

Il primo tentativo mostrava **interno = 0%**. Il collegamento fra attività e
commessa usava il codice dell'attività invece dell'identificativo del contratto:
tutte le attività risultavano a cliente.

Me ne sono accorto perché zero ore interne era implausibile su un portale con 76
commesse interne. Se dopo l'aggiornamento vedete verde e rosso a zero,
verificate che `cm_projects.dgb_contract_id` sia popolato — attesi circa 1.050
valori su 1.062 commesse.
