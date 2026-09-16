# Manuale Amministratore — v1.8.43

## Esportare tutti i record

Il menu **Esporta** dell'Anagrafica dipendenti presenta in testa la casella
**"Tutti i record (ignora i filtri)"**.

Lasciandola vuota il comportamento è quello di sempre: si esporta ciò che si sta
vedendo, filtri compresi. Spuntandola si ottiene l'elenco integrale, a
prescindere dai filtri attivi; il nome del file riporta il suffisso `_completo`
così da distinguerlo a colpo d'occhio, e un avviso conferma quante righe sono
state estratte.

La casella vale per tutti e quattro i formati e compare in ogni elenco del
portale dotato di filtri, non solo nell'Anagrafica.

## Il template di import

**Amministrazione → Import dipendenti XLSX** offre ora due pulsanti di download,
XLSX e CSV. Il template riporta **tutti** i campi dell'Anagrafica: 32 colonne, che
diventano 30 per chi non ha il permesso Compensation, poiché RAL e premio
concordato seguono la stessa visibilità della colonna RAL nell'elenco.

Il file XLSX contiene due fogli. **Dipendenti** ha le intestazioni e una riga di
esempio da sostituire. **Istruzioni** riporta per ogni colonna se è obbligatoria,
il formato atteso e una nota esplicativa.

Solo **Cognome** e **Nome** sono obbligatorie. Le altre colonne possono restare
vuote o essere rimosse dal file.

## Perché template e import non possono divergere

Il tracciato è definito una sola volta, in `app/EmployeeImportSchema.php`. Il
template che si scarica, le colonne che l'import riconosce e l'elenco mostrato
nella pagina derivano tutti da quella definizione: sono allineati per
costruzione, non per verifica successiva.

## Aggiornamenti parziali

In aggiornamento le celle vuote **non** sovrascrivono i valori già registrati. È
la proprietà che rende pratico un template così ampio: per correggere il livello
CCNL di venti persone basta compilare codice fiscale, cognome, nome e livello,
lasciando vuoto tutto il resto.

Il riconoscimento di un dipendente già presente avviene per **codice fiscale** e,
in mancanza, per **matricola**.

## I campi di collocazione organizzativa

Dipartimento, sotto-categoria e modalità di lavoro sono cercati per nome fra
quelli già configurati. Se un valore non viene riconosciuto il campo resta vuoto
ma **la riga viene comunque importata**: un import di anagrafica che fallisse
perché una modalità di lavoro non è ancora stata creata sarebbe più dannoso che
utile. Conviene quindi controllare, dopo un import massivo, che le assegnazioni
di dipartimento siano quelle attese.

Azienda e sede mantengono il comportamento precedente, con auto-creazione
opzionale attivabile nell'anteprima.

## Compatibilità con i file esistenti

I file conformi ai tracciati precedenti continuano a funzionare: le vecchie
intestazioni sono accettate come sinonimi, comprese le forme abbreviate come
`%PT`, `CF`, `Tipo rapporto`. Non serve riconvertire nulla.

## Verifica consigliata

Prima di un caricamento massivo: scaricare il template, compilare una sola riga
con un codice fiscale già presente, importarla e controllare in anagrafica che i
campi compilati risultino aggiornati e gli altri invariati.
