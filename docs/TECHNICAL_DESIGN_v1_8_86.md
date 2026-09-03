# Technical Design — PortalManager v1.8.86

## 1. Che cosa è attribuibile a una persona

Il dato disponibile è il messaggio, con autore e istante. Da lì si possono
costruire due misure molto diverse.

**Contare i messaggi** è immediato e sbagliato come misura di rendimento: chi
interviene a metà conversazione su un ticket già avviato accumula messaggi senza
esserne responsabile.

**La prima risposta di supporto** identifica invece chi ha preso in carico il
ticket. L'esito del ticket — risolto, scalato — è allora attribuibile a quella
persona.

La scheda espone entrambe, in due gruppi separati e con l'etichetta che dice quale
delle due è attribuibile.

## 2. Una sola presa in carico per ticket

```sql
JOIN (SELECT ticket_code, MIN(received_at) AS primo …) p
  ON p.ticket_code = m.ticket_code AND p.primo = m.received_at
WHERE m.source_id = (SELECT MIN(y.source_id) FROM cm_sd_messages y
                      WHERE y.ticket_code = m.ticket_code
                        AND y.msg_type = 'SUPPORT_MSG'
                        AND y.received_at = m.received_at)
```

Il solo `MIN(received_at)` non basta: due risposte nello stesso istante
produrrebbero due righe per lo stesso ticket, e la somma dei presi in carico
supererebbe il numero dei ticket.

Il secondo criterio su `source_id` risolve il pari merito in modo deterministico.
Verificato: **2.941 righe per 2.941 ticket**.

## 3. Il tempo di prima risposta è l'unico misurabile senza SLA

Distanza fra apertura del ticket e prima risposta di supporto.

A differenza della durata totale, **non dipende dalle attese del cliente**: quelle
intervengono solo dopo la prima risposta. È quindi un tempo pulito, confrontabile
fra persone, e non richiede di sottrarre gli intervalli in
`WAITING_FOR_CUSTOMER_RESPONSE`.

Resta un tempo *osservato*, non un rispetto di SLA: senza soglie definite non c'è
niente con cui confrontarlo.

## 4. La media dei pari come scala

```php
$pari = array_filter($confronto, fn($c) => $c['tecnico'] !== $tec);
$mediaOre = array_sum(array_map(…, $pari)) / count($pari);
```

Un numero isolato non è interpretabile: 9,2 ore sono molte o poche?

La media **esclude il tecnico stesso**, altrimenti chi ha il volume più alto
sposterebbe la media verso di sé e il confronto perderebbe sensibilità.

La soglia di evidenza sul tasso di escalation è il 40% sopra la media: abbastanza
alta da non accendersi per fluttuazioni, abbastanza bassa da cogliere il caso di
Bressi (10,8% contro 5,1% di media).

## 5. Il grafico a barre impilate

Risposte in blu, note interne in grigio, impilate sulla stessa barra.

L'alternativa — due serie affiancate — avrebbe dimezzato la larghezza di ciascuna
barra su dodici mesi. Impilando, l'altezza totale è il volume del mese e la
composizione si legge nella proporzione dei due colori.

Il collaudo verifica che `risposte + note ≤ altezza del grafico`: se la scala
fosse calcolata su una sola delle due serie, le barre uscirebbero dal riquadro.

## 6. Collaudo esteso alle espressioni del template

La v1.8.85 aveva mostrato il limite: un collaudo che si ferma al modello dà zero
errori su una pagina che non si apre.

`qa_scheda.php` esegue ora anche i calcoli che stanno nel template — medie dei
pari, percentuali, altezze delle barre — con gli avvisi convertiti in eccezioni. E
un controllo separato verifica che ogni `$sd->metodo()` invocato dalla pagina
esista davvero in `SdModel`.

Non è ancora un rendering completo — servirebbe simulare sessione e permessi — ma
copre la parte dove gli errori si erano manifestati.

## 7. Il caso limite del tecnico inesistente

`scheda()` restituisce array vuoto se il nome non esiste, e la pagina non mostra
il riquadro. Verificato in collaudo.

Serve perché il nome arriva dall'URL: un parametro modificato a mano, o un
segnalibro salvato dopo che un tecnico ha cambiato nome, non devono produrre una
pagina rotta.
