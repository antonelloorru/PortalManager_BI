# Manuale Amministratore — v1.8.89

## Che cosa contiene questa release

**Le fondamenta della Relazione di Servizio IT**: le viste che espongono ogni
intervento con tutte le sue dimensioni, e il modello che le legge.

**La pagina non è ancora inclusa** — lo dico apertamente. Le fondamenta sono
verificate, e la pagina si costruisce su queste.

## I dati disponibili

67.723 interventi, 145 incaricati, 21 linee di servizio. Per ciascun intervento:
incaricato, settore tecnologico, linea di servizio, modello contrattuale, cliente,
sede di riferimento, ore, ore extra, ore di viaggio, durata, modalità, fascia
oraria.

| Modalità | Interventi | Ore |
|---|---|---|
| In sede | 45.073 | 253.900,0 |
| Da remoto | 15.896 | 41.858,0 |
| **Presso cliente** | **5.107** | **35.915,0** |
| Smart working | 1.497 | 6.382,0 |
| In reperibilità | 150 | 348,5 |

La modalità è **esclusiva**: i flag del gestionale non lo sono — un intervento può
essere insieme in reperibilità e da remoto — quindi ho stabilito una precedenza
dal più specifico al più generico. La reperibilità qualifica l'intervento
indipendentemente da dove venga svolto.

## Il settore tecnologico dipende da voi

Viene dall'unità organizzativa assegnata al profilo tecnico. Sul database di prova
risulta uno solo perché nessun profilo ha l'unità; **sul vostro server ne avete 27
assegnati**, quindi vedrete quelli.

Più assegnazioni fate in *Unità Organizzative Tecniche*, più la ripartizione per
settore diventa significativa.

## I chilometri: come attivarli

Ho realizzato entrambe le strade che avevate scelto.

**Le ore di viaggio funzionano già**: circa 10.100 ore su 5.107 trasferte. Misurano
lo stesso fenomeno dei km con un dato reale.

**Le distanze richiedono tre passi.**

1. **Gli indirizzi.** Lanciate la sincronizzazione: importa gli indirizzi dei
   clienti. Le sedi ne hanno solo 10 su 169 — vanno completate a mano.

2. **La lista di lavoro.**
   ```sql
   SELECT * FROM v_cm_it_distanze_mancanti ORDER BY interventi DESC LIMIT 50;
   ```
   Le coppie sede-cliente ordinate per peso: le prime cinquanta coprono la maggior
   parte degli interventi.

3. **Popolare le distanze**, a mano oppure con geocodifica:
   ```sql
   INSERT INTO cm_it_distances (location_id, client_id, km_one_way, source)
   VALUES (3, 118, 42.5, 'manuale');
   ```

La geocodifica automatica richiede una chiave API di un servizio di mappe: va
deciso quale usare, e non l'ho inclusa per questo.

**I km restano vuoti finché non ci sono, non zero.** Con zero la media
chilometrica includerebbe le trasferte non misurate come se fossero state a
distanza nulla. La copertura è mostrata come indicatore: oggi 0 su 104 trasferte.

## Giornate lavorate

Sono la coppia **incaricato + giorno**: chi fa cinque interventi in un giorno ha
lavorato un giorno.

Su luglio 2026: 1.756 interventi, 932 giornate-uomo, 6.761,5 ore → **7,25 ore
medie per giornata**. Il valore è plausibile e conferma il calcolo.

## Filtri combinabili

Ogni dimensione accetta più valori: si sommano fra loro e restringono fra
dimensioni diverse.

| Filtro | Interventi |
|---|---|
| base (2026) | 16.855 |
| remoto **+** smart working | 4.831 |
| 3 linee insieme | 3.937 |
| 3 linee **AND** da remoto | 613 |

Il raggruppamento accetta fino a dieci dimensioni combinate.
