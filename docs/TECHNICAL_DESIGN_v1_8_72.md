# Technical Design — PortalManager v1.8.72

## 1. Un difetto che il collaudo non poteva vedere

`php -l` verifica la sintassi, non l'ordine di esecuzione. Un `foreach` su una
variabile non ancora definita è sintatticamente valido.

E il comportamento dipende dalla configurazione: con `display_errors = Off`
l'avviso finisce nel log, `$NAT` vale `null`, il `foreach` non itera e la legenda
semplicemente **non appare**. Nessun errore visibile, solo un elemento mancante
che si può non notare.

Con `display_errors = On` — l'impostazione tipica di XAMPP — lo stesso avviso
viene stampato nella pagina. E qui il danno si estende: quel testo esce prima
delle intestazioni HTTP, quindi **rompe anche l'export**, che è il secondo
sintomo segnalato.

Un solo difetto, due manifestazioni apparentemente scollegate.

## 2. Definire prima di usare, anche nei template

Il codice era distribuito fra blocchi `<?php ?>` intervallati da HTML, e la
sequenza di esecuzione segue l'ordine del **file**, non la struttura logica del
riquadro. La legenda sta in alto nel markup, le definizioni erano più in basso
insieme alla tabella.

Correzione: tutte le definizioni — `$NAT`, `$oreOrd`, `$cella` — in un unico
blocco in testa al riquadro. È una regola semplice da rispettare e da verificare:

```
$NAT definita a offset 53846, primo riferimento in codice a 54278  OK
```

Il controllo ha dovuto distinguere i riferimenti in **commento** da quelli in
codice, perché i commenti che spiegano il difetto citano la variabile prima della
sua definizione.

## 3. Permesso di visibilità contro appartenenza

`dgb_operator_can_see_forms_division` ha un nome che lo dice — *can see* — ma la
tentazione di usarlo come appartenenza era forte: è l'unica tabella che lega
operatori a divisioni.

La verifica che ha chiuso la questione:

```sql
SELECT n_div, COUNT(*) FROM (
  SELECT id_operator, COUNT(*) n_div FROM dgb_operator_can_see_forms_division
   GROUP BY id_operator) t GROUP BY n_div;
```

Nessun operatore con una sola divisione, minimo due, media quattro. Una relazione
di appartenenza avrebbe una forte concentrazione su uno; questa distribuzione
descrive autorizzazioni.

Usarla per attribuire ore a divisioni avrebbe moltiplicato ogni ora per quattro,
producendo totali che nessuno avrebbe riconosciuto come sbagliati fino a un
confronto con il consuntivo complessivo.

## 4. La dimensione sta sulla commessa

`forms_contract.id_division` è valorizzato su **tutte** le 808 commesse. È il
legame che il gestionale dichiara, ed è univoco: una commessa appartiene a una
divisione.

L'attribuzione delle ore diventa allora indiretta ma solida — le ore di una
divisione sono quelle spese sulle sue commesse — e non richiede alcuna
inferenza sulla persona.

È anche più corretto concettualmente per un'analisi economica: il margine
appartiene alla commessa, e la commessa a una divisione. Attribuire margine a una
persona richiederebbe di ripartire costi indiretti, che è un'altra questione.

## 5. `<=>` invece di `=` nel join

```sql
) r ON r.`dc` <=> p.`division_code`
```

L'operatore di uguaglianza null-safe. Con `=`, le 315 commesse senza divisione
non si unirebbero al loro aggregato — `NULL = NULL` è `NULL`, non vero — e le
loro ore sparirebbero dalla vista invece di comparire sotto *(non assegnata)*.

Una riga «non assegnata» con 25.047 ore è un'informazione. Quelle stesse ore
assenti dal totale sarebbero un errore silenzioso.

## 6. Che cosa resta da capire sulle divisioni

Tre divisioni — Laboratorio, WENEST, WeEnengys — esistono in anagrafica ma non
hanno commesse. Possono essere strutture dismesse, o nuove, o che operano su
commesse attribuite ad altre divisioni.

Non è un difetto dei dati: è una domanda per chi conosce l'organizzazione, e va
posta prima di leggere quelle righe come «divisioni improduttive».
