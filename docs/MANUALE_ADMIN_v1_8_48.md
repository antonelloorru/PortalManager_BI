# Manuale Amministratore — v1.8.48

## Due pagine nuove

**Gestione Commesse → Unità Organizzative Tecniche** definisce la tassonomia.
**Gestione Commesse → Anagrafica Tecnica** classifica le persone.

Si lavora nell'ordine: prima si verifica che le unità corrispondano alla vostra
organizzazione, poi si classificano i tecnici.

## Le unità organizzative

Nove unità sono precaricate: Presidio, Sistemista Infrastruttura, Sistemista DB,
Sistemista Network, Service Desk, Help Desk, SOC, Reperibilità, Reperibile H24.
Ognuna ha 2–4 sotto-unità, per esempio Virtualizzazione e Storage sotto
Sistemista Infrastruttura.

Si possono aggiungere, rinominare, riordinare e colorare. Il colore compare
accanto al nome nell'anagrafica e aiuta a leggere l'elenco a colpo d'occhio.

Il flag **Unità di reperibilità** marca le unità che comportano interventi fuori
orario.

### Perché non si eliminano

Rimuovendo un'unità in uso il portale la **disattiva** anziché eliminarla, e lo
dice. Il motivo è lo storico: un'unità può non avere più nessun assegnato oggi ed
essere citata da decine di variazioni passate. Eliminandola, quelle righe
resterebbero con un riferimento a nulla.

Un'unità disattivata sparisce dalle tendine di assegnazione ma resta leggibile
ovunque sia già citata. Le unità mai usate si eliminano davvero.

## L'anagrafica tecnica

Un solo elenco con tecnici interni e professionisti esterni, distinti da
un'etichetta. Gli esterni già collegati a un dipendente non compaiono due volte.

I dati anagrafici **non si modificano qui**: nome, email e recapiti restano nelle
schede di origine — Anagrafica dipendenti per gli interni, Anagrafica
Professionisti per gli esterni. Questa pagina aggiunge solo la classificazione
operativa: unità, sotto-unità, seniority, reperibilità, turno, competenza
principale, certificazioni.

È una scelta voluta: se i dati fossero copiati, correggere un cognome in un punto
lascerebbe il vecchio nell'altro, e nessuno dei due sarebbe riconoscibile come
quello giusto.

### Le schede in testa

Una scheda per unità con il numero di tecnici e, dove presenti, quanti sono
reperibili. Cliccandole si filtra l'elenco. La scheda rossa **Da classificare**
conta chi non ha ancora un'unità: dopo la prima installazione sono tutti.

### Classificare

L'icona a destra apre il pannello. La tendina delle sotto-unità mostra solo
quelle dell'unità scelta; se si prova a forzare una combinazione incoerente, il
salvataggio la rifiuta.

**Reperibile** e **H24** sono distinti: il primo indica la disponibilità su
chiamata, il secondo la copertura continuativa.

## Lo storico

Al salvataggio c'è la casella **Registra questa variazione nello storico**, con
un campo per il motivo.

Non è automatica di proposito. Se ogni salvataggio finisse a storico, l'archivio
si riempirebbe di correzioni di refusi e la domanda per cui lo storico esiste —
*quando questa persona è passata dal Service Desk al SOC* — richiederebbe di
distinguere a mano i cambi veri dal rumore.

Spuntatela solo per i veri cambi di assegnazione. L'assegnazione precedente viene
chiusa automaticamente il giorno prima della nuova decorrenza.

Le denominazioni sono **congelate** al momento della registrazione: se rinominate
un'unità, lo storico continua a riportare il nome di allora, così il passato non
viene riscritto.

## Il collegamento con DGB

Nel tab **Consuntivo** della scheda commessa la colonna si chiama ora
"Rapporto / Codice DGB". Dove il rapporto è agganciato a un'attività DGB, il
codice è cliccabile e porta ad Attività & Rendicontazione già filtrata su quella
riga.

Il codice rapporto e il codice attività coincidono, quindi l'aggancio è stato
fatto dalla migration su tutti i rapporti importati da DGB. Dove manca — rapporti
inseriti a mano — il codice resta in chiaro con un trattino grigio.

Nella pagina DGB è stato aggiunto il campo **Codice attività o ticket**, che rende
il filtro visibile e removibile.

## Le analisi che diventano possibili

Con i tecnici classificati si può rispondere a domande che prima richiedevano un
foglio a parte: quante ore consuntiva il SOC rispetto al Service Desk, quanti
reperibili H24 ci sono per azienda, come è cambiata la distribuzione fra unità nel
tempo — quest'ultima grazie allo storico.
