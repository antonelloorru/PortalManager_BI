# Manuale Amministratore — v1.8.65

## Che cosa significava quel messaggio

*«Anteprima: nessuna scrittura effettuata, e limitata a 200 righe per dataset»*
significa che è stata eseguita l'**anteprima** invece della sincronizzazione: il
portale ha letto 200 righe per tipo di dato, ha calcolato che cosa avrebbe fatto,
e **non ha scritto nulla**.

Il messaggio era corretto. Il problema è che descriveva un'operazione che non
avevate chiesto.

## La causa

I due pulsanti *Anteprima completa* e *Sincronizza tutto* erano dentro lo stesso
modulo. In HTML il valore di un pulsante viene trasmesso solo se il browser lo
riconosce come quello che ha inviato il modulo: se l'invio avviene in altro modo —
premendo Invio, o cliccando sull'icona dentro il pulsante — il server riceve il
**primo** pulsante del modulo.

Il primo era *Anteprima completa*.

## Come si riconosce ora

Il titolo del riquadro dell'esito porta un'etichetta colorata:

- **ANTEPRIMA** arancione — nessun dato scritto
- **SCRITTURA** verde — sincronizzazione eseguita

È la prima cosa che si vede. Prima la distinzione stava in un avviso sotto il
titolo, facile da non leggere quando si è convinti di aver premuto l'altro
pulsante.

La modalità è registrata anche nell'event log, quindi a posteriori si sa che cosa
è stato eseguito:

```sql
SELECT created_at, message FROM event_log
 WHERE message LIKE 'Sync completa richiesta%' ORDER BY id DESC;
```

## Verifica che la sincronizzazione abbia scritto

```sql
SELECT COUNT(*) FROM cm_project_allocations;   -- atteso ~69.300
SELECT COUNT(*) FROM cm_contract_rates;        -- atteso ~24.300
```

Se sono a zero o a 200, è stata un'anteprima.

## Quando usare l'anteprima

L'anteprima resta utile: verifica che tutti i dataset rispondano senza scrivere
nulla, ed è veloce perché legge solo 200 righe per tipo. Conviene lanciarla dopo
un aggiornamento o un cambio di configurazione, prima della sincronizzazione
vera.

Ma i suoi numeri **non sono i volumi reali**: indicano che cosa verrebbe fatto su
quelle 200 righe.
