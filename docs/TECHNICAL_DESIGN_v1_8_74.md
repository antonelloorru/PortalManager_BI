# Technical Design — PortalManager v1.8.74

## 1. Tre categorie, non due

La richiesta «tutti i dati devono derivare dalla sincronizzazione» sembra
implicare una verifica binaria: sincronizzata o no. In realtà le tabelle sono
tre, e la terza è quella che conta.

| Categoria | Esempio | Trattamento |
|---|---|---|
| dato del gestionale | `cm_projects`, `clients` | deve essere sincronizzato |
| decisione del portale | `cm_tech_units`, `cm_rate_bands` | non deve esserlo |
| registro tecnico | `cm_import_batches` | né l'uno né l'altro |

La distinzione non è deducibile dallo schema: una tabella senza dataset può
essere un difetto o una scelta. Da qui `v_cm_copertura_sync`, che la rende
esplicita e verificabile invece di lasciarla nella testa di chi l'ha fatta.

## 2. `clients` era derivata, non importata

Il popolamento avveniva come effetto collaterale della sincronizzazione dei
rapporti: dal testo del cliente si cercava una corrispondenza e, se assente, si
creava la riga.

È un meccanismo che funziona e degrada in silenzio. Produce solo i clienti che
compaiono nei rapporti — quindi manca chi non ha ancora avuto interventi — e solo
il nome, perché è l'unica cosa che il testo del rapporto contiene.

Il confronto: 305 righe nel portale contro 338 aziende cliente nel gestionale, e
zero partite IVA contro 137 disponibili.

Un dataset esplicito porta l'anagrafica completa. Il meccanismo per derivazione
resta come rete di sicurezza per i clienti che comparissero in un rapporto prima
di essere anagrafati.

## 3. La chiave sul nome, e le sue conseguenze

`clients` non ha una colonna per l'identificativo del gestionale. Aggiungerla
sarebbe stato più pulito, ma le 305 righe esistenti non l'avrebbero valorizzata:
la riconciliazione con quelle righe può avvenire **solo sul nome**.

La conseguenza è che i sette nomi duplicati nella sorgente violano la chiave.
Tre strade:

1. importarne uno a caso — perde la partita IVA nella metà dei casi;
2. scartarli — perde sette clienti;
3. aggregare per nome tenendo il valore migliore di ciascun campo.

```sql
MAX(NULLIF(TRIM(COALESCE(c.vat_number,'')),'')) AS `partita iva`
```

`NULLIF` su stringa vuota trasforma i valori assenti in NULL, e `MAX()` li ignora
restituendo il valore vero quando esiste. Verificato: FIUMICINO LOGISTICA
conserva `09706451003`, che una scelta arbitraria avrebbe perso nel 50% dei casi.

## 4. Perché le anagrafiche interne non vanno sincronizzate

`cm_tech_units` e `cm_tech_profiles` contengono decisioni: quale unità
organizzativa, quale fascia, da quando. Il gestionale non le conosce — le sue
divisioni sono un'altra cosa, come verificato nella v1.8.72.

Sincronizzarle richiederebbe una sorgente che non esiste, e il risultato pratico
sarebbe svuotarle.

`employees` è il caso più delicato: il gestionale ha `dgb_operator` con nome e
full cost, il portale ha contratti, retribuzioni, badge, date di assunzione.
Sovrapporre i due significherebbe far vincere il meno informativo.

Il legame esiste e va mantenuto — `cm_tech_profiles.employee_id` e
`professional_id` — ma è un **riferimento**, non una copia: l'identità sta nel
gestionale, la classificazione nel portale, e ciascuno resta padrone del proprio.

## 5. I clienti in testa all'ordine

```
divisioni → clienti → commesse → … → rapporti
```

Commesse e rapporti si agganciano al cliente. Sincronizzarlo dopo lascerebbe
righe scollegate fino alla passata successiva — non un errore, ma un'analisi
incompleta per chi guarda subito dopo.

Le divisioni restano prime perché sono una dimensione pura, senza dipendenze
nemmeno dai clienti.
