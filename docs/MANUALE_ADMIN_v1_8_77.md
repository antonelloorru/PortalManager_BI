# Manuale Amministratore — v1.8.77

## Il problema era molto più ampio della segnalazione

Nushi Irni su WTS_3670 era reale: 34 moduli, 66 ore. Ma non era un caso isolato.

**Su tutti i 69.074 rapporti il tecnico non era collegato all'anagrafica.** Il
nome esisteva solo come testo.

Per questo il sintomo sembrava incoerente: i moduli d'intervento mostrano il
testo, quindi il tecnico appare; allineamento team e report ore collegano
all'anagrafica per identificativo, quindi sparisce. Due pagine che leggono
correttamente due campi diversi.

## La causa

La sincronizzazione risolveva la **commessa** ma non il **tecnico**: quella parte
non era mai stata scritta. Non un difetto introdotto di recente, ma qualcosa che
mancava fin dall'inizio e che non produceva errori visibili.

## L'anagrafica era già corretta

```
cm_professionals  id=179  Nushi Irni   → collegato al dipendente 86
employees         id=86   Irni Nushi
```

Il collegamento c'era già, e la riconciliazione aveva anche rilevato che nome e
cognome sono invertiti fra gestionale e anagrafica HR. Mancava solo di riportare
quel riferimento sui rapporti.

## Risultato

| | Prima | Dopo |
|---|---|---|
| Con professionista | **0** | **69.074** |
| Con dipendente | **0** | 66.939 |
| **Scollegati** | **69.074** | **0** |

Nushi Irni su WTS_3670 ora punta ai record corretti, inversione inclusa.

**Fate un backup prima**: la migration aggiorna 69.074 righe e richiede qualche
minuto.

## Due correzioni

La **migration** ripara i rapporti già presenti. La **sincronizzazione** risolve
il tecnico d'ora in poi, così il problema non si ricrea.

## Il controllo da tenere

```sql
SELECT * FROM v_cm_tecnici_scollegati;
```

**Deve restituire zero righe.** Elenca i tecnici che non trovano corrispondenza
in anagrafica, con quante ore pesano.

Se dopo una sincronizzazione futura comparisse qualcuno, è una persona nuova non
ancora in anagrafica: basta inserirla e al giro successivo si collega da sola.

## Sui 2.135 rapporti senza dipendente

Hanno il professionista ma non il dipendente: sono persone non ancora
riconciliate fra le due anagrafiche.

**Restano visibili in tutti i prospetti** — il collegamento al professionista
basta. Riconciliarle in *Anagrafica Tecnica* completerebbe il quadro, ma non è
urgente.

## Una scelta che ho fatto

La risoluzione usa solo corrispondenze **esatte**: nome diretto, nome invertito,
sigla. Niente ricerche approssimate, che pure recupererebbero qualche caso in
più.

Un rapporto attribuito alla persona sbagliata sposta ore e costi su chi non li ha
sostenuti, e non se ne accorge nessuno perché il totale resta giusto. Un tecnico
non attribuito invece compare nel controllo e si corregge.
