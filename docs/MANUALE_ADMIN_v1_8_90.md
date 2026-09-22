# Manuale Amministratore — v1.8.90

## La sezione Relazione di Servizio IT

**Gestione Commesse → Relazione di Servizio IT**. Sui vostri dati del 2026:

| Indicatore | Valore |
|---|---|
| Interventi | 16.855 |
| **Giornate-uomo** | **9.401** |
| Ore | 71.835,0 → **7,6 h per giornata** |
| Ore a ricavo | 53.015,5 (**73,8%**) |
| Ore di viaggio | 2.188,5 |

**Giornate-uomo** è la coppia incaricato + giorno: chi svolge cinque interventi in
un giorno ha lavorato una giornata. È l'unica misura che permette di calcolare le
ore medie giornaliere.

## I filtri si combinano

Sette menu a **selezione multipla** — tenere premuto Ctrl per scegliere più valori:

- più valori sulla **stessa** dimensione **si sommano**
- dimensioni **diverse** **si restringono**

Tre linee di servizio danno 3.937 interventi; le stesse tre più «da remoto» ne
danno 613.

**Raggruppa per** accetta anch'esso più dimensioni: `settore × modalità × fascia
oraria` produce una riga per combinazione.

## Export con foglio pivot

**XLSX + pivot** produce sei fogli. Uno è la matrice **incaricato × linea di
servizio**: 107 incaricati per 19 linee, 2.033 celle.

L'ho costruita nell'export e non a video perché a doppia entrata di quelle
dimensioni è illeggibile in una pagina, mentre in Excel è esattamente la forma su
cui si costruisce un grafico pivot in due clic.

Le celle senza ore restano **vuote e non zero**: con 1.900 zeri le poche celle
piene si perderebbero, e un grafico costruito su quella matrice avrebbe gli assi
schiacciati.

## Il report di stampa a colori

Si apre in una scheda nuova, **A4 orizzontale** — la tabella ha quattordici
colonne — con i grafici a colori e i filtri applicati riportati in testa.

**Per stampare i colori attivate «Grafica di sfondo»** nelle opzioni di stampa del
browser: è disattivata per impostazione predefinita per risparmiare inchiostro.

Una precisazione utile: i **grafici escono a colori comunque**, perché sono
vettori e il colore dei tracciati non è uno sfondo. Sono le aree piene dei
riquadri in testa a scomparire senza quell'opzione.

## Il settore tecnologico dipende da voi

Viene dall'unità organizzativa del profilo tecnico. Sul database di prova risulta
un solo settore perché nessun profilo ha l'unità assegnata.

**Sul vostro server ne avete 27 su 104 profili**: più ne completate in *Unità
Organizzative Tecniche*, più la ripartizione per settore diventa significativa.

## I chilometri

Restano a zero finché non ci sono gli indirizzi, e la pagina **lo dichiara con un
avviso** invece di mostrare una colonna vuota senza spiegazione.

Nel frattempo le **2.188,5 ore di viaggio** misurano lo stesso fenomeno con un
dato reale già registrato.

Per attivare i km servono i tre passi descritti nel deployment della v1.8.89:
sincronizzare gli indirizzi dei clienti, completare quelli delle sedi (10 su 169),
popolare le distanze.
