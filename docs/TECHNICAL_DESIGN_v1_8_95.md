# Technical Design — PortalManager v1.8.95

## 1. Riuso del canale esistente

`SmtpMailer` era già configurato e verificato. L'alerting lo istanzia con le
stesse impostazioni lette da `app_settings`.

Introdurre un secondo canale — anche solo una classe diversa che usa `mail()` —
avrebbe significato due configurazioni da tenere allineate, e al primo cambio di
password una delle due sarebbe rimasta indietro producendo errori su metà delle
email.

L'unica aggiunta è l'**alias**, che non è un secondo canale ma un mittente
diverso sullo stesso: `alert_alias_email` con ripiego su `mail_from`.

## 2. Rilevazione e invio separati

```php
public function rileva(bool $dryRun = false): array
public function invia(bool $dryRun = null): array
```

Due metodi, due tabelle, due fasi invocabili singolarmente.

Il collaudo lo ha confermato nel caso che conta: con l'SMTP irraggiungibile, i
quattro errori sono stati tracciati in `cm_alert_sent` e **zero eventi sono stati
marcati come inviati**. La successiva esecuzione riprova senza dover ririlevare.

Con una tabella sola, l'errore di invio avrebbe perso anche la rilevazione — e la
condizione sarebbe stata segnalata solo al successivo cambio di fascia, cioè forse
mai.

## 3. La firma e la fascia

```sql
CONCAT('budget_consumo|', c.commessa, '|', FLOOR(consumo_valore_pct / 10) * 10)
```

L'unicità su `signature` è ciò che impedisce il reinvio: `INSERT IGNORE` non crea
una seconda riga per una condizione già nota.

**Perché la fascia e non il valore esatto**: con il valore, un consumo che passa
da 85,0 a 85,1 genererebbe una firma diversa e quindi una nuova email. La fascia
a decine rende insensibile la firma alle oscillazioni e sensibile ai
peggioramenti reali.

La granularità è per metrica: decine per il consumo, cinquine per il margine (che
si muove meno), 90 giorni per il fermo, e per la scadenza solo due valori — 30 e
7 — perché quello che conta è aver superato la soglia, non di quanto.

## 4. Chiusura invece di cancellazione

```sql
UPDATE cm_alert_events SET resolved_at = NOW()
 WHERE resolved_at IS NULL AND signature NOT IN (…)
```

Le condizioni non più presenti fra le correnti vengono chiuse.

Cancellarle avrebbe reso impossibile rispondere a «da quanto tempo questa commessa
è in sofferenza», che è la domanda che si pone chi la vede tornare in elenco per
la terza volta.

Un evento chiuso che si ripresenta genera una riga nuova, perché la vecchia ha
`resolved_at` valorizzato ma la firma è unica — il vincolo di unicità impedirebbe
l'inserimento. È un limite noto: la firma andrebbe estesa con la data di
riapertura se servisse tracciare i cicli.

## 5. Raggruppamento per destinatario

```php
foreach ($eventi as $e) {
    if ($e['to_agent'] && …) $per[$emailAgente]['ev'][] = $e;
    if ($e['to_director'] && …) $per[$direttore]['ev'][] = $e;
}
```

Lo stesso evento finisce in due gruppi — quello dell'agente e quello del direttore
— e produce due email diverse, ciascuna con il proprio insieme di righe.

Sui dati: 434 eventi diventano **4 messaggi**. Una email per evento sarebbe stata
tecnicamente più semplice e praticamente inutilizzabile.

`FIELD(severity,'critico','allarme','attenzione')` nell'ordinamento porta le
righe più gravi in cima alla tabella dell'email.

## 6. La copia è della persona, non della regola

`cm_alert_recipients` ha `agent_name`, `email`, `cc_email`.

Mettere la copia in `cm_alert_rules` avrebbe richiesto una regola per ogni
combinazione agente-criterio: con 45 agenti e 6 regole, 270 righe di
configurazione per esprimere 45 preferenze.

`agent_name` è testuale e non un id perché `cm_projects.commercial_ref` è un nome:
non esiste un'anagrafica degli agenti a cui agganciarsi. Il vincolo di unicità
sul nome impedisce almeno le doppie configurazioni.

## 7. Due protezioni contro l'invio accidentale

**Spento di fabbrica**: `alert_enabled = 0`, `alert_dry_run = 1`. Un aggiornamento
non fa partire email a 45 persone la notte stessa.

**Tetto per esecuzione**: `alert_max_per_run = 50`. Un errore che moltiplicasse
gli eventi — una regola con la soglia a zero — non si traduce in centinaia di
messaggi prima che qualcuno se ne accorga.

Il tetto interrompe l'invio ma non la rilevazione: gli eventi restano in coda per
l'esecuzione successiva.

## 8. Il vincolo dichiarato nel messaggio

Il piè di pagina di ogni email dice che la segnalazione non verrà ripetuta.

Senza, il destinatario che non interviene subito aspetta un promemoria che non
arriva, e conclude che il sistema non funziona. Dichiarare il comportamento
costa due righe ed evita una richiesta di assistenza.
