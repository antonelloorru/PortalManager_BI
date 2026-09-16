# Manuale Amministratore — v1.8.95

## Il canale di posta è quello che già usate

`SmtpMailer` era configurato e verificato — Aruba, porta 465, SSL. L'alerting lo
riusa: due sistemi di invio avrebbero significato due configurazioni da tenere
allineate, e al primo cambio di password una sarebbe rimasta indietro.

Ho aggiunto solo l'**alias**: un mittente dedicato alle comunicazioni sulle
commesse, così chi riceve le distingue dalle notifiche di scadenza e può
filtrarle.

## Le soglie che avete concordato

| Regola | Attenzione | Allarme |
|---|---|---|
| Consumo del budget | **75%** | **90%** |
| Budget sforato | — | oltre 100% |
| Margine sotto il minimo | — | **sotto 20%** |
| Consumo in anticipo | 20 punti | 35 punti |
| In scadenza | 30 giorni | 7 giorni |
| Senza movimenti | 90 giorni | 180 giorni |

Sono **in tabella, non nel codice**: le avete stabilite voi e potete cambiarle
senza una release.

Sui vostri dati sono **434 condizioni rilevabili**: 216 sforate, 168 ferme, 22 in
scadenza, 16 sotto margine, 10 in divergenza, 2 al 75%.

## I destinatari, come richiesto

| Chi | Riceve |
|---|---|
| **Agente** | solo le sue commesse |
| **Direttore** | tutte |
| **Copia** | indirizzo configurabile **per singolo agente** |

La copia sta nella tabella dei destinatari e non nelle regole, perché è una
proprietà della **persona**: nelle regole avrebbe richiesto 270 righe di
configurazione (45 agenti × 6 regole) per esprimere 45 preferenze.

## Il sistema parte spento

`alert_enabled = 0` e `alert_dry_run = 1`: rileva e registra, **non spedisce**.

Un aggiornamento non deve far partire email a 45 persone la notte stessa. Lo
accendete voi dopo aver verificato che destinatari e conteggi siano corretti.

C'è anche un **tetto di 50 messaggi per esecuzione**: se una configurazione
sbagliata moltiplicasse gli eventi, non si traduce in centinaia di email prima che
qualcuno se ne accorga.

## Perché non ricevete la stessa email ogni giorno

**Ogni segnalazione viene inviata una sola volta per livello di soglia.**

Una commessa all'85% resta all'85% per settimane. Senza questo vincolo, il
destinatario riceve la stessa email ogni giorno e in due settimane smette di
leggerle — **incluse quelle nuove**.

| Passaggio | Esito |
|---|---|
| dal 79,8% all'80,1% | **nuovo alert** (cambia fascia) |
| dall'85% all'86% | nessun invio |
| dall'80% al 90% | **nuovo alert** |

Ho usato le fasce a decine e non il valore esatto proprio per questo: con il
valore, un consumo da 85,0 a 85,1 avrebbe generato una email.

Le condizioni rientrate si **chiudono** da sole, e restano in tabella: servono a
rispondere a «da quanto tempo questa commessa è in sofferenza».

## Una email per persona, non per problema

Un agente con 105 commesse critiche riceve **una email con 105 righe**, non 105
email.

Sui vostri dati: 434 eventi diventano **4 messaggi** — direttore 434 righe, agenti
105, 76 e 37.

## Se un invio fallisce

L'errore viene registrato e **gli eventi non vengono marcati come inviati**: la
successiva esecuzione riprova.

L'ho verificato spegnendo l'SMTP: 4 errori tracciati, **zero eventi persi**. È la
ragione per cui rilevazione e invio sono tabelle separate.

```sql
SELECT recipient, error_msg, sent_at FROM cm_alert_sent
 WHERE status = 'errore' ORDER BY sent_at DESC;
```

## Il pannello

Nel report direzionale, in testa: stato, mittente, regole attive, condizioni
rilevabili, eventi aperti, in attesa, inviate e errori degli ultimi 7 giorni.

Vi avvisa se mancano i destinatari, e se sono configurati per meno agenti di
quanti ne esistono — in quel caso i loro alert arrivano solo al direttore.

## I riepiloghi cadenzati non sono ancora attivi

Le regole `riepilogo_sett` e `riepilogo_mens` sono predisposte ma disattivate: il
motore di invio periodico non è implementato.

C'è una differenza che conta: un riepilogo va inviato **anche se vuoto** —
«nessuna criticità» è un'informazione, mentre un alert che non arriva è ambiguo
(tutto bene, o il sistema è fermo?). Richiede una logica a calendario diversa da
quella a evento.
