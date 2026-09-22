# Technical Design — PortalManager v1.8.48

## 1. Modello dell'anagrafica tecnica

```
employees ──────┐
                ├──► cm_tech_profiles ──► cm_tech_units ──► cm_tech_subunits
cm_professionals┘            │
                             └──► cm_tech_history
```

`cm_tech_profiles` ha due colonne alternative, `employee_id` e
`professional_id`, entrambe con vincolo UNIQUE. Una persona è interna oppure
esterna: il controllo applicativo rifiuta la valorizzazione di entrambe, e il
vincolo di unicità impedisce due profili per la stessa persona.

L'alternativa sarebbe stata una tabella anagrafica autonoma con i dati copiati.
È stata scartata perché avrebbe introdotto il problema che ogni sistema con dati
duplicati finisce per avere: due verità che divergono. Correggere un cognome in
Anagrafica dipendenti avrebbe lasciato il vecchio nella scheda tecnica, e
nessuna delle due sarebbe stata riconoscibile come quella giusta.

Il costo di questa scelta è una `UNION ALL` nella query di elenco. È un costo
accettabile: le due sorgenti insieme contano centinaia di righe, non milioni.

## 2. Perché la tassonomia sta in una pagina separata

Unità organizzative e assegnazioni cambiano con ritmi diversi. Le persone si
spostano di continuo; la struttura si riorganizza raramente, ma quando accade
tocca molte persone insieme.

Tenerle nella stessa pagina avrebbe significato o ripetere la definizione
dell'unità in ogni scheda, o modificare la struttura da dentro la scheda di un
singolo tecnico — che è il punto sbagliato da cui farlo.

## 3. Eliminazione contro disattivazione

```php
$used = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM cm_tech_profiles WHERE unit_id=$id)
                               + (SELECT COUNT(*) FROM cm_tech_history WHERE unit_id=$id)")->fetchColumn();
```

Il conteggio include **lo storico**, non solo i profili correnti. È la parte che
conta: un'unità può non avere più nessun tecnico assegnato oggi ed essere citata
da decine di righe di storico. Eliminarla lascerebbe quelle righe con un
riferimento morto.

Quando l'unità è in uso viene disattivata: sparisce dalle tendine di
assegnazione ma resta leggibile ovunque sia già citata. Il messaggio spiega la
scelta invece di limitarsi a rifiutare.

## 4. Storicizzazione volontaria

La casella "Registra questa variazione nello storico" è una decisione di
progetto, non una scorciatoia.

Uno storico automatico su ogni `UPDATE` produce righe per ogni salvataggio,
comprese le correzioni di refusi e i cambi di nota. Su un archivio così, la
domanda per cui lo storico esiste — *quando questa persona è passata dal Service
Desk al SOC* — richiede di distinguere a mano le variazioni vere dal rumore.

Lasciando la decisione a chi salva, ogni riga di storico è un cambio di
assegnazione dichiarato, con un motivo scritto.

### Chiusura del periodo precedente

```sql
UPDATE cm_tech_history SET valid_to = DATE_SUB(?, INTERVAL 1 DAY)
 WHERE profile_id = ? AND valid_to IS NULL AND valid_from < ?
```

`valid_to` NULL identifica l'assegnazione corrente. La chiusura al giorno
precedente la nuova decorrenza produce periodi contigui e non sovrapposti,
verificato in collaudo con una query di auto-join che cerca intersezioni.

La condizione `valid_from < ?` evita di chiudere una riga che inizia lo stesso
giorno o più tardi, caso che si presenta correggendo una registrazione appena
fatta.

### Etichette congelate

Lo storico salva `unit_label` e `subunit_label` oltre agli identificativi. È
duplicazione voluta: se un'unità viene rinominata, la riga di storico deve
continuare a dire com'era chiamata allora, altrimenti il passato viene riscritto
retroattivamente. Gli identificativi restano per le analisi aggregate, le
etichette per la lettura storica.

## 5. Coerenza unità / sotto-unità

Una sotto-unità appartiene a una sola unità. Assegnare "Monitoraggio" (del SOC) a
una persona classificata "Sistemista Infrastruttura" produrrebbe una combinazione
che nessuna analisi saprebbe interpretare.

Il controllo è su due livelli: lato client le opzioni non pertinenti vengono
nascoste al cambio di unità, lato server il salvataggio verifica la proprietà e
rifiuta. Il secondo è quello che conta — il primo evita solo di far sbagliare.

## 6. Legame Consuntivo ↔ DGB

I codici coincidono, ma `dgb_source_id` non era riusabile: ha un vincolo UNIQUE
ed è scritto dalla sincronizzazione DGB con l'identificativo dell'**allocazione**,
non dell'attività. Un intervento con due tecnici produce due rapporti sulla stessa
attività: con quel vincolo il secondo verrebbe rifiutato.

Le nuove colonne `dgb_activity_id` e `dgb_activity_code` non hanno unicità. La
seconda è ridondante rispetto a un join, ma evita di doverlo fare per mostrare il
codice in una tabella già ricca di join.

Il popolamento usa `SUBSTRING_INDEX(report_code, '/', 1)`, che rimuove il suffisso
del tecnico introdotto in v1.8.46 sui rapporti multi-tecnico.

### L'indice mancante

La UPDATE andava in lock wait timeout: `dgb_forms_activity.code` non aveva
indice, e con 80.000 attività ogni rapporto causava una scansione completa —
5,4 miliardi di confronti. Con `idx_dfa_code` l'operazione diventa immediata.

È il tipo di problema che non si vede su dati di prova e si manifesta solo in
produzione: la migration crea l'indice **prima** della UPDATE.

## 7. Sicurezza

Tutte le scritture passano da prepared statement con placeholder. Le due pagine
verificano i permessi separatamente per vista, modifica ed eliminazione; i
permessi sono derivati da quelli dell'Anagrafica Professionisti, quindi
l'introduzione non allarga gli accessi già concessi.

I form GET emettono `route_slug_field()` come primo elemento, secondo la regola
della v1.8.42.
