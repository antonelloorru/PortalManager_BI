# Technical Design — PortalManager v1.8.90

## 1. Il report riceve il contesto, non lo ricostruisce

`it_service_print.php` è **incluso** da `it_service.php` dopo che i dati sono
stati caricati, e usa le stesse variabili: `$tot`, `$righe`, `$trend`, `$gMod`, e
anche `$barre`, la funzione che disegna i grafici.

L'alternativa — una pagina autonoma che rilegge i dati — avrebbe permesso a
pannello e report di divergere: due query scritte in momenti diversi, un filtro
aggiunto in un posto e non nell'altro. Il report avrebbe mostrato numeri
plausibili e diversi da quelli appena guardati.

Il costo è che il report non è raggiungibile senza la pagina. Accettabile: non
esiste un caso in cui serva stamparlo senza averlo prima visto.

## 2. Barre orizzontali

Le ripartizioni usano barre orizzontali con l'etichetta a sinistra.

Con barre verticali le etichette — «Sistemista Infrastruttura», «Attività interna
- AI», nomi e cognomi — andrebbero ruotate di 90° o troncate a poche lettere. Su
dieci voci la ruotazione rende il grafico alto il doppio e faticoso da leggere.

La funzione è definita una volta e usata sia a video sia in stampa: cinque
grafici, due contesti, un solo punto in cui correggere.

## 3. Barre impilate per l'andamento

Le ore fuori orario sono disegnate **sopra** le ore totali, non accanto.

Impilando, l'altezza totale è il volume del mese e la porzione ambra è la quota
fuori orario: la proporzione si legge senza confrontare due grafici affiancati.

Il collaudo verifica che la barra ambra non superi quella blu: se le due serie
venissero da query con filtri diversi, il grafico mostrerebbe una quota superiore
al 100% e nessuno se ne accorgerebbe leggendo i numeri.

## 4. Il pivot si costruisce nell'export, non a video

107 incaricati × 19 linee = 2.033 celle. In una pagina web è una tabella che
richiede scorrimento su entrambi gli assi e non si legge.

In un foglio di calcolo è invece la forma naturale: si seleziona e si inserisce un
grafico pivot in due clic.

```php
$r[] = $v ?: null;   // celle vuote invece di zeri
```

Le combinazioni senza ore restano vuote. Con gli zeri, un pivot di 2.033 celle
mostrerebbe 1.900 zeri e le poche celle piene si perderebbero — e un grafico
costruito su quella matrice avrebbe assi schiacciati dai valori nulli.

## 5. A4 orizzontale

La tabella di dettaglio ha quattordici colonne più quelle del raggruppamento.

In verticale servirebbe un corpo di 5 punti o il troncamento di metà colonne.
L'orizzontale costa un orientamento inusuale e restituisce una tabella leggibile.

Il salto di pagina prima del dettaglio è esplicito: i grafici stanno sulla prima
pagina, la tabella sulle successive. Senza, la tabella comincerebbe a metà della
prima e i grafici verrebbero spinti in alto in modo irregolare.

## 6. I colori in stampa

```css
* { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
```

I browser, per impostazione predefinita, **non stampano gli sfondi colorati** per
risparmiare inchiostro. La proprietà chiede il contrario, ma i browser la
rispettano solo se l'utente non ha disattivato *«Grafica di sfondo»* nelle
opzioni.

È dichiarato nel deployment e nel manuale perché è la causa più probabile di un
report stampato in bianco e nero, e non è correggibile lato server.

Gli **SVG** invece si stampano sempre: sono vettori, e il colore dei tracciati non
è uno sfondo. I grafici escono a colori anche con la grafica di sfondo
disattivata; sono le aree piene dei riquadri KPI a scomparire.

## 7. Le dimensioni di raggruppamento sono un elenco chiuso

```php
$gb = array_values(array_intersect($gb, array_keys(self::DIM)));
```

Arrivano dalla richiesta HTTP e finiscono interpolate in un `GROUP BY`: non
possono essere accettate senza filtro.

L'elenco chiuso serve anche da documentazione — dice quali combinazioni la
sezione supporta — e fornisce le etichette senza una seconda mappa da tenere
allineata.

## 8. Tre quadrature nel collaudo

| Quadratura | Verifica |
|---|---|
| somma per modalità = totale ore | la classificazione è esaustiva |
| somma per durata = interventi | ogni intervento ha una durata |
| somma del pivot = totale ore | il raggruppamento a due dimensioni non perde righe |

La terza è quella che intercetta gli errori di `GROUP BY`: con una chiave
incompleta le righe si moltiplicano e la somma supera il totale; con un `JOIN`
sbagliato alcune spariscono e la somma scende.
