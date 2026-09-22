# Technical Design — PortalManager v1.9.5

## 1. Una riga elementare, cinque aggregazioni

`v_cm_sd_moduli` espone un modulo del team con tutte le sue dimensioni: tecnico,
fascia, contratto, modalità, ore.

Le quattro viste che seguono vi si appoggiano. Costruirle ciascuna sui join
avrebbe significato ripetere sei volte la stessa catena — moduli, ponte dei nomi,
commesse, modelli contrattuali, attività DGB, allocazione — e cambiarla in sei
punti quando cambia.

Il costo è un livello di annidamento. Accettabile qui perché le aggregazioni sono
`GROUP BY` semplici: l'ottimizzatore ha mostrato di sbagliare sulle sottoquery
correlate (v1.8.88), non su queste.

## 2. La fascia oraria riusata, non riscritta

```sql
WHEN DAYOFWEEK(report_date) IN (1, 7) THEN 'fuori orario'
WHEN a.date_start IS NULL             THEN 'non rilevata'
WHEN TIME_TO_SEC(...) BETWEEN 32400 AND 46800
  OR TIME_TO_SEC(...) BETWEEN 50400 AND 64800 THEN 'in orario'
ELSE 'fuori orario'
```

Identica a `v_cm_it_servizio`. Copiarla è meno elegante di una funzione condivisa,
ma MariaDB non permette di riusare un `CASE` fra viste senza un livello ulteriore
di annidamento.

Ciò che conta è che sia la **stessa** regola: se Service Desk e Relazione IT
dessero fasce diverse sullo stesso intervento, nessuno saprebbe quale credere.

Il fine settimana è valutato **per primo**, prima dell'ora. Un intervento
domenicale alle 10 cadrebbe altrimenti nella finestra 09–13 e risulterebbe «in
orario».

## 3. `non rilevata` è una terza categoria

Se il modulo non ha l'attività DGB collegata, l'ora di inizio manca.

Assegnarlo a «fuori orario» avrebbe gonfiato quella quota con casi ignoti;
assegnarlo a «in orario» l'avrebbe sgonfiata. Una terza etichetta dice che il dato
manca, ed è verificabile: se cresce, il collegamento fra moduli e attività si sta
degradando.

## 4. Contratto e fascia sulla stessa riga

`v_cm_sd_team_contratto` raggruppa per contratto **e** espone le ore per fascia
come colonne.

Due viste separate — una per contratto, una per fascia — avrebbero risposto a due
domande e non alla terza: *su quali contratti si lavora fuori orario*. Quella è
l'informazione che ha un effetto pratico, perché un canone erogato fuori orario
costa più di quanto il canone preveda.

## 5. Ticket e moduli affiancati, di nuovo

È la terza volta che questa scelta si ripresenta: v1.8.87 sulle schede, v1.8.92
sui codici, ora sulla squadra.

La ragione è sempre la stessa e vale la pena fissarla: non esiste una colonna che
leghi un modulo di intervento al ticket che lo ha originato. Senza quel legame la
sovrapposizione non è misurabile, e sommare due grandezze parzialmente
sovrapposte produce un numero che non corrisponde a nulla.

Se il gestionale esponesse il riferimento al ticket sul modulo, la somma
diventerebbe possibile — e sarebbe la prima cosa da fare.

## 6. Il filtro tecnico concatenato

```php
. ($f['tec'] !== '' ? " AND m.`tecnico` = ?" : "")
```

Come nella v1.8.88: con la scheda di un componente aperta, ogni riquadro della
pagina deve riferirsi a lui.

Un riquadro «Analisi del Team» che mostrasse il totale di squadra accanto a una
scheda personale verrebbe letto come suo.
