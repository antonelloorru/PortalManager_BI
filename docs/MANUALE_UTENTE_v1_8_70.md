# Manuale Utente — v1.8.70

## Le ore consuntivate risulteranno dimezzate

Non si è perso nulla: erano ore **contate due volte**.

Una vecchia versione della sincronizzazione registrava gli interventi con un
codice inventato invece del codice reale del gestionale. Quando il difetto è
stato corretto, gli stessi interventi sono stati importati di nuovo con il codice
giusto — ma le vecchie righe erano rimaste.

Il portale conteneva quindi ogni intervento due volte, con due codici diversi.
Il controllo di unicità non poteva accorgersene, perché i due codici sono
effettivamente diversi.

## Cosa cambia

| | Prima | Dopo |
|---|---|---|
| Rapporti di intervento | 136.828 | 69.042 |
| Ore consuntivate | 673.024 | **344.395** |

Tutti i prospetti — marginalità, saldo commessa, distribuzione oraria, anomalie —
mostreranno valori circa dimezzati. **Sono quelli corretti**: i precedenti erano
gonfiati dal doppio conteggio.
