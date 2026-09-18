# Manuale Amministratore — v1.9.6

## Il filtro per componente

Nella barra dei filtri, fra Coda e Livello, trovate **Componente del team**.

Prima il filtro esisteva ma si impostava **solo cliccando un nome** nelle tabelle
in fondo alla pagina: un filtro raggiungibile per collegamento esiste e non si
trova.

Il menu è ordinato **per cognome** e riporta la **sotto-unità** accanto al nome:
in una tendina il nome da solo costringe a ricordare chi sta in quale livello.

Comprende anche gli **specialisti fuori dall'unità** che hanno preso in carico
ticket: un elenco limitato ai quattro del team non avrebbe permesso di filtrare su
di loro.

## Il riepilogo nella stampa

Il **Report generale** conteneva la tabella dei componenti sull'**intero
archivio**, ma non il riepilogo del **periodo stampato**.

Un report di periodo che riporta solo dati storici costringe chi legge a
confrontare a mente due grandezze diverse — e la nota che lo dichiara non basta a
evitare l'errore.

Ora contiene entrambe:

| Tabella | Periodo |
|---|---|
| I componenti del Service Desk | intero archivio |
| **Riepilogo attività del periodo** | **periodo selezionato** |
| **Attività per tipologia di contratto** | periodo selezionato |

Il riepilogo porta cinque indicatori — ticket presi, moduli, ore, fuori orario,
giornate-uomo — e il dettaglio per componente con ticket e moduli affiancati.

## I componenti con zero restano

Chi non ha lavorato nel periodo **resta in elenco con zero righe**.

Non ha lavorato, non è uscito dalla squadra: farlo sparire confonderebbe le due
cose, e chi legge non saprebbe se cercarlo altrove.

## Se filtrate su chi non è più nel team

Resta nel menu marcato **«fuori squadra»**. Senza, il menu mostrerebbe «Tutta la
squadra» mentre il filtro è attivo — e i numeri sarebbero quelli di una persona
sola senza che nulla lo dica.
