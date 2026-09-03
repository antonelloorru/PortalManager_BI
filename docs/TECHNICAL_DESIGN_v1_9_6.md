# Technical Design — PortalManager v1.9.6

## 1. Un filtro raggiungibile solo per collegamento non è un filtro

`tec` era pienamente funzionante dalla v1.8.88: restringeva ogni riquadro della
pagina. Si impostava però solo cliccando un nome in una tabella in fondo.

Chi apre la sezione per guardare una persona deve prima scorrere fino in fondo,
trovare la tabella giusta e capire che il nome è cliccabile. La funzione c'era,
l'accesso no.

Il costo di aggiungerla alla barra è un `<select>`; il beneficio è che smette di
essere una scoperta fortuita.

## 2. L'elenco comprende chi non è nel team

```sql
SELECT … FROM v_cm_sd_team
UNION
SELECT … FROM v_cm_sd_messaggi WHERE author_name NOT IN (SELECT nome FROM v_cm_sd_team)
```

Il filtro funziona su qualunque autore di messaggi, non solo sui quattro
dell'unità. Un menu limitato al team avrebbe reso irraggiungibile una capacità
che il filtro già ha.

`ORDER BY in_team DESC, ordina` mette prima la squadra: sono i nomi che si
cercano più spesso, e mescolarli con quaranta specialisti li renderebbe difficili
da trovare.

## 3. Il tecnico fuori elenco

```php
if ($tec !== '' && !in_array($tec, array_column($elencoTeam, 'nome'), true))
```

Se il filtro è attivo su un nome che il menu non contiene — un tecnico rimosso
dall'unità, o un elenco cambiato fra due caricamenti — il `<select>` mostrerebbe
la prima opzione, «Tutta la squadra».

I dati sarebbero filtrati e il menu direbbe il contrario. L'opzione aggiunta
marcata «fuori squadra» evita la contraddizione.

## 4. Due tabelle, due periodi, entrambe dichiarate

Il report generale conteneva la tabella dei componenti sull'intero archivio.

Aggiungere il riepilogo del periodo crea una situazione delicata: due tabelle con
le stesse colonne e numeri diversi. Senza indicazione, chi legge assume che siano
la stessa cosa e conclude che una delle due sia sbagliata.

Ciascuna dichiara il proprio periodo nel titolo o nella nota. Il riepilogo porta
le date nel titolo, dove non si possono ignorare.

L'alternativa — sostituire la tabella storica — avrebbe perso l'informazione su
chi sono i componenti e quanto pesano storicamente, che è il senso della tabella
originale.

## 5. Zero è un valore, non un'assenza

`v_cm_sd_team_dettaglio` parte da `v_cm_sd_team` in `LEFT JOIN`: un componente
senza attività nel periodo compare con zero.

Un `INNER JOIN` lo avrebbe fatto sparire, e chi legge non saprebbe se non ha
lavorato o se è uscito dalla squadra. Sono due situazioni con conseguenze diverse,
e la riga a zero le distingue.

È lo stesso principio del fallback sui nomi non in anagrafica (v1.8.91): far
sparire una riga è un errore silenzioso, mostrarla vuota è un'informazione.

## 6. Il bilanciamento dei tag

Il riepilogo si inserisce in mezzo a un blocco condizionale già annidato, e i
`<div>` vanno chiusi nel punto giusto.

Un `<div>` sbilanciato in un report di stampa non produce errori PHP: produce una
pagina in cui una sezione ingloba le successive, visibile solo stampando.

Il controllo conta le aperture e le chiusure nel blocco di stampa: 58 e 58.
Grossolano ma sufficiente a intercettare il caso in cui ne manchi uno.
