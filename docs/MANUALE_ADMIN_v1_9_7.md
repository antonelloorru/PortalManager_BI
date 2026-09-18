# Manuale Amministratore — v1.9.7

## Le assenze del team

Nuovo riquadro in **Service Desk**: ferie, permessi, recupero ore, malattia — con
il dettaglio per componente e l'andamento mensile.

Sui vostri dati: **1.436,5 ore su 4 persone, 179,6 giornate**.

| Voce | Ore |
|---|---|
| Ferie | 1.187,0 |
| Permessi | 129,0 |
| Malattia | 72,0 |
| Recupero ore | 40,5 |
| **Altre** | **8,0** |

## Una categoria che ho trovato collaudando

Le quattro voci sommavano 1.428,5, il totale diceva 1.436,5. **Mancavano 8 ore.**

La causa è una riga con tutte le voci a zero e il totale valorizzato — Chiarini,
10 agosto 2026: un tipo di assenza che la v1.8.81 non aveva classificato.

Avevo tre possibilità: ignorarlo, sommare le quattro voci al posto del totale, o
esporre la differenza.

- **Ignorarlo** avrebbe lasciato una tabella in cui i numeri non tornano
- **Sommare le quattro voci** avrebbe fatto sparire 8 ore di assenza reale
- **Esporre la differenza** dice che esiste una categoria non classificata

Ho scelto la terza. **Se sapete di che assenza si tratta, ditemelo e la
classifico**: basta aggiungerla alle regole della v1.8.81.

## Le visite non entrano nel totale

Le loro ore sono **già comprese nelle altre voci**: nel gestionale non esiste un
tipo dedicato, e vengono riconosciute dalla descrizione.

Le espongo perché quantificano un fenomeno altrimenti invisibile, ma sommarle
conterebbe due volte le stesse ore. La nota lo ripete ovunque compaiano, perché
una colonna in una tabella di totali viene istintivamente sommata.

## I report riprendono la schermata

Entrambi — generale e personale — contengono ora:

- il **grafico dell'andamento** dei ticket per classe di gestione
- il **riquadro delle assenze** con grafico mensile
- nel generale, il dettaglio per componente

I grafici sono **vettori**: escono a colori anche senza l'opzione «Grafica di
sfondo» del browser. Quell'opzione serve per le aree piene dei riquadri in testa —
senza, i numeri restano leggibili ma i riquadri escono bianchi.

## Le giornate

Calcolate su **8 ore**, come nella sezione Presidi. Lì è un parametro in tabella,
qui è cablata: se la convenzione cambiasse, andrebbe cambiata in due punti. È
un'incoerenza minore che preferisco dichiarare.
