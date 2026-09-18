# Manuale Amministratore — v1.8.75

## Sincronizzazione giornaliera automatica

In **Sincronizzazione gestionale** trovate il riquadro **Sincronizzazione
giornaliera pianificata**.

| Parametro | A cosa serve |
|---|---|
| Pianificazione attiva | interruttore generale |
| Ora di esecuzione | l'orario previsto, per esempio 02:00 |
| Finestra di recupero | minuti entro cui recuperare un'esecuzione mancata |
| Giorni | quali giorni della settimana |
| Riconcilia | rimuove anche le righe che il gestionale non ha più |

Consigliato: **02:00, tutti i giorni, finestra 120 minuti**. Attivate la
riconciliazione solo dopo aver verificato a mano che non produca rimozioni
inattese.

## Serve un passo su Windows

Il portale decide *se* è il momento di sincronizzare, ma **non può avviarsi da
solo**: qualcuno deve invocarlo.

**Utilità di pianificazione** → *Crea attività*:

| Scheda | Impostazione |
|---|---|
| Generale | *Esegui anche se l'utente non ha effettuato l'accesso* |
| Attivazione | Giornaliero 00:00, *Ripeti ogni* **1 ora** per **1 giorno** |
| Azioni | `P:\xampp\php\php.exe`<br>Argomenti: `P:\xampp\htdocs\portalmanager\cron_sync.php --quiet` |
| Condizioni | togliere *Avvia solo se il computer è alimentato* |

**Perché ogni ora e non alle 02:00**: se l'attività girasse solo alle 02:00 e il
server fosse spento, la sincronizzazione salterebbe il giorno. Girando ogni ora,
lo script trova la finestra ancora aperta al primo avvio utile.

Fuori dalla finestra termina in meno di un secondo: l'attività oraria non pesa.

E il vantaggio: **l'orario si cambia dal portale**, senza rimettere mano a
Windows.

## Come verificare

Da prompt dei comandi, per provare subito:

```
P:\xampp\php\php.exe P:\xampp\htdocs\portalmanager\cron_sync.php --force --dry-run
```

`--dry-run` non scrive nulla e **non aggiorna l'ultima esecuzione**, quindi la
prova non fa saltare la sincronizzazione vera del giorno.

## Il controllo che conta

Il riquadro mostra una diagnosi in evidenza:

| Diagnosi | Significato |
|---|---|
| **regolare** | tutto a posto |
| **IN RITARDO** | attiva ma non gira da oltre 36 ore |
| **ultima esecuzione fallita** | l'ultimo tentativo è andato in errore |
| **mai eseguita** | l'attività di Windows non è stata creata |

*IN RITARDO* è quello da guardare: una pianificazione rotta va vista subito, non
scoperta notando che i dati sono vecchi.

Sotto il riquadro, *Ultime esecuzioni* riporta le ultime dieci con righe lette,
nuove, aggiornate e durata.

## Due comportamenti da conoscere

**Una sincronizzazione fallita riprova** entro la finestra: se alle 02:05 il
gestionale non era raggiungibile, alle 03:00 ci riprova. Una riuscita no.

**Due esecuzioni non si sovrappongono**: un blocco impedisce che l'attività
oraria ne avvii una seconda mentre la prima è in corso. Il blocco scade dopo 3
ore, così un arresto anomalo del server non congela la pianificazione per sempre.
