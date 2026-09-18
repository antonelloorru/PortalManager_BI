# Manuale Amministratore — v1.8.71

## Perché serviva questa funzione

La sincronizzazione **aggiunge e aggiorna, ma non rimuove**. Una riga che sparisce
dal gestionale — cancellata, riclassificata, o prodotta da un import ormai
superato — resta nel portale per sempre.

È così che il consuntivo si era riempito di 67.786 rapporti fantasma. La pulizia
della versione precedente risolveva quel caso specifico; questa funzione lo rende
un **controllo ripetibile su tutti e dodici i dataset**.

## Come si usa

**Sincronizzazione gestionale** → **Verifica allineamento**.

Non modifica nulla. Mostra per ogni tipo di dato quante righe ha il gestionale,
quante ne ha il portale, e quante sono **orfane** — presenti da voi ma non più
nella sorgente.

Solo se ne trova compare il pulsante **Riallinea al gestionale**, con il numero
esatto di righe da rimuovere e una richiesta di conferma.

Sono due passi separati di proposito: un'operazione che cancella righe non deve
essere raggiungibile con un clic senza sapere che cosa si sta per perdere.

## Guardate gli esempi di chiave

Il riquadro mostra fino a cinque chiavi orfane per dataset. È la parte più utile.

Se mostrano un **pattern riconoscibile** — un prefisso, un formato di codice —
state rimuovendo un gruppo omogeneo, ed è il caso normale.

Se invece sembrano chiavi legittime sparse, conviene capire perché la sorgente non
le restituisce più prima di cancellarle. Una causa possibile e innocua: un filtro
nella query del dataset che esclude righe che prima includeva — in quel caso non
sono orfane, è la query a essere cambiata.

## Cosa non viene mai rimosso

Le righe **inserite a mano o caricate da XLSX**. Non provengono dal gestionale,
quindi la sua assenza non le rende orfane: eliminarle distruggerebbe lavoro che
nessuno può ricostruire.

Le trovate contate nella colonna **Protette**.

## Prima di riallineare

**Fate un backup.** L'operazione non è reversibile.

## Un limite da conoscere

La riconciliazione confronta **chiavi**. Non riconosce righe duplicate sotto
chiavi diverse: se lo stesso intervento esistesse due volte con due codici che il
gestionale conosce entrambi, per questa funzione sarebbero due righe legittime.

Nel caso dei rapporti fantasma funzionava perché quei codici non esistevano nella
sorgente. Lo dico perché una funzione chiamata "riallinea" può dare l'impressione
di garantire più di quanto garantisca.
