# Manuale Amministratore — v1.8.88

## Il difetto che avete segnalato

Aprendo la scheda di un componente, i riquadri sopra e sotto restavano
**generali**: i quattro indicatori, la ripartizione, l'andamento e le tabelle
mostravano i dati di tutti.

Affiancati a una scheda personale sembravano suoi. **Un pannello che dice 3.512
ticket sopra la scheda di chi ne ha presi 520 induce in errore** senza contenere
un solo dato sbagliato — ed è il motivo per cui nessun controllo automatico
l'aveva rilevato.

Ora il tecnico è un filtro vero: **ogni riquadro della pagina si riferisce a lui**.

| | Ticket | Presi | Escalation | Operatori |
|---|---|---|---|---|
| Nessun filtro | 3.512 | 1.470 | 7,1% | 40 |
| Sebastiano Chiarini | **520** | 520 | 6,2% | **1** |
| Emanuele Bressi | 278 | 278 | 10,8% | 1 |

I quattro indicatori in testa coincidono ora esattamente con quelli della scheda.

## I report stampabili

Con un tecnico selezionato compare **Report personale**, altrimenti **Report
generale**. Si apre in una scheda nuova, con un pulsante *Stampa* che non viene
stampato.

| Report | Contenuto |
|---|---|
| **Personale** | quadro del periodo, gestione, esito dei ticket presi, attività complessiva, moduli per tipologia di contratto, code seguite |
| **Generale** | quadro, gestione, tabella dei componenti, ticket da presidiare |

È una pagina **senza menu né barre laterali**: l'intestazione del portale in un
documento consegnato a terzi è rumore, e i menu sprecherebbero metà foglio.

**Per stampare i colori delle barre**, attivate *«Grafica di sfondo»* nelle opzioni
di stampa del browser: è disattivata per impostazione predefinita.

## Le avvertenze sono sul foglio

Ogni report chiude ripetendo che la durata comprende le attese del cliente e che
nessun SLA è definito.

Nel pannello quelle note le legge chi ha impostato i filtri. **Un documento
stampato circola senza di lui**: i limiti devono viaggiare con i numeri.

## Export XLSX

Con un tecnico selezionato l'export ha **otto fogli** invece di quattro: si
aggiungono Scheda, Contratti, Code e Ticket presi in carico. Il nome del file
riporta la persona.

## Una nota tecnica che potrebbe servirvi

Il filtro usa un **JOIN** e non `IN` o `EXISTS`. Su queste viste — che sono
costruite su altre viste — MariaDB risolve male le sottoquery: restituivano
**2 ticket dove il join ne trova 520**.

L'ho verificato in SQL puro: non è un errore della condizione ma della sua
risoluzione. Se in futuro qualcuno aggiungesse filtri su queste viste, conviene
usare la stessa forma.
