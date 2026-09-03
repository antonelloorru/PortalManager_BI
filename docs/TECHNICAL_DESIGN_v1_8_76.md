# Technical Design — PortalManager v1.8.76

## 1. Due elementi, due logiche

| Elemento | Presenza | Domanda a cui risponde |
|---|---|---|
| scheda KPI | sempre | «a quando risale l'aggiornamento?» |
| banner | solo se problema | «c'è qualcosa che devo fare?» |

La tentazione era un solo riquadro sempre visibile, verde o rosso. Non funziona:
un elemento permanente che è verde il 95% dei giorni smette di essere letto, e
quando diventa rosso nessuno lo nota.

La scheda KPI resta perché sta in una griglia di numeri che si consulta
deliberatamente. Il banner occupa spazio in testa alla pagina e deve guadagnarselo.

## 2. L'ultima riuscita, non l'ultima riga

```sql
SELECT started_at, status, rows_read, datasets_ok, datasets_err
  FROM cm_sync_schedule_log
 WHERE status IN ('ok','parziale')
 ORDER BY started_at DESC LIMIT 1
```

Il filtro sullo stato è il punto. Senza, un tentativo fallito due ore fa
diventerebbe «ultima sincronia: 2 ore fa» — l'indicatore direbbe che i dati sono
freschi proprio quando non lo sono.

`parziale` è incluso: alcuni dataset sono passati, quindi qualcosa si è
aggiornato. Il banner lo segnala comunque tramite `ultimo_esito`.

Le due informazioni sono indipendenti e vengono mostrate insieme: *«l'ultima
sincronizzazione è fallita»* nel titolo, *«ultima completa: 30 ore fa»* nel
dettaglio. Chi legge sa sia che c'è un problema sia quanto sono vecchi i dati.

## 3. Le soglie

| Soglia | Valore | Motivo |
|---|---|---|
| giallo | 26 ore | una sincronizzazione notturna che slitti di un'ora non è un problema |
| rosso e banner | 36 ore | oltre un giorno e mezzo almeno un'esecuzione è saltata |

24 ore esatte avrebbero prodotto un giallo quasi ogni giorno, perché fra una
notte e l'altra passano 24 ore più il tempo di esecuzione. Una soglia che scatta
nel funzionamento normale è rumore.

## 4. Il filtro sul ruolo

```php
if ($u_role <= 5) { … }
```

Non è una questione di sicurezza — il dato non è riservato — ma di attenzione.

Un avviso mostrato a chi non può agire produce due effetti: quella persona
impara a ignorare gli avvisi, e magari segnala il problema a chi di dovere
generando una comunicazione che il sistema già fa da solo.

## 5. Degradazione silenziosa

```php
try { … } catch (Throwable $e) { $syncAvviso = null; }
```

Se le tabelle della v1.8.75 non ci sono — installazione non aggiornata,
migration non eseguita — l'avviso semplicemente non compare.

La home è la prima pagina che tutti aprono: un errore lì rende il portale
inutilizzabile per un elemento accessorio. Meglio che manchi un avviso piuttosto
che non si apra nulla.

## 6. Formato relativo, dato assoluto nel suggerimento

La scheda mostra `5h fa`, non `19/08 02:00`. Un tempo relativo si interpreta senza
calcoli: «5 ore fa» dice subito che va bene, «02:00» richiede di sapere che ore
sono e quando era prevista.

Il valore assoluto sta nel `title`: chi ha bisogno della data esatta la ottiene
passando il mouse, chi vuole un'occhiata non deve fare aritmetica.

La dimensione del carattere si riduce quando il testo supera i cinque caratteri,
perché `mai` e `12g fa` non hanno lo stesso ingombro e la griglia dei KPI deve
restare allineata.
