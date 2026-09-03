# Technical Design — PortalManager v1.8.56

## 1. Una sola clausola per due consumatori

Il modo naturale di aggiungere un export a una tabella filtrata è scrivere una
seconda query. È anche il modo in cui i due si disallineano: si aggiunge un
filtro alla pagina, ci si dimentica dell'export, e da quel momento il file
contiene qualcosa di diverso da ciò che l'utente stava guardando.

Il difetto è insidioso perché non produce errori: produce un file plausibile con
i dati sbagliati.

```php
$anomWhere = function (array $f): array {
    $w = ['1=1']; $a = [];
    if ($f['tipo']    !== '') { $w[] = 'tipo = ?';       $a[] = $f['tipo']; }
    if ($f['tecnico'] !== '') { $w[] = 'tecnico LIKE ?'; $a[] = '%' . $f['tecnico'] . '%'; }
    …
    return [implode(' AND ', $w), $a];
};
```

Una funzione sola, invocata da entrambi. Lo stesso vale per l'ordinamento
(`$anomOrder`), per le intestazioni (`$anomCols`) e per la trasformazione della
riga (`$anomRow`): quattro definizioni condivise, nessuna duplicata.

Aggiungere un filtro significa toccare un punto, e vale automaticamente per
entrambi.

## 2. Il limite a video, non nell'export

A video l'elenco è limitato a 500 righe: una pagina con migliaia di righe è
lenta da caricare e inutile da leggere.

L'export non ha limite. Chi esporta sta portando il dato altrove — in un foglio,
in un'analisi — e la prima pagina non gli serve a nulla.

La conseguenza è che i due numeri differiscono, e la differenza va **dichiarata**.
L'intestazione della tabella riporta entrambi:

```
500 di 546 righe (prime 500 a video)
```

Un utente che veda 500 righe e ne esporti 546 senza preavviso sospetta un difetto.
Lo stesso utente informato in anticipo capisce che è il comportamento voluto.

Per lo stesso motivo il conteggio totale è calcolato con una query separata di
`COUNT(*)` e non con `count()` sull'array: quest'ultimo restituirebbe 500 e
nasconderebbe la differenza.

## 3. Colonne allineate in entrambe le direzioni

La versione precedente mostrava a video sette colonne ed esportava le stesse
sette. Allineare i due significava scegliere quale insieme adottare.

Sono state aggiunte a video **tipo** e **commesse coinvolte**: il tipo perché con
il filtro per tipo disattivato la tabella mescola due famiglie di anomalie e
distinguerle a occhio dalla sola descrizione è faticoso; le commesse coinvolte
perché sono il dato che qualifica la gravità di un'anomalia di ore duplicate.

## 4. Il filtro sul tecnico

Ricerca parziale con `LIKE`, non selezione esatta. I nomi provengono dal
gestionale e la loro forma non è garantita — spaziature, ordine di nome e
cognome, secondi nomi. Una tendina a selezione esatta funzionerebbe finché i nomi
restano identici a se stessi.

L'elenco a discesa è comunque fornito, popolato dai nomi effettivamente presenti
nelle anomalie: suggerisce senza vincolare.

## 5. Le schede di riepilogo preservano il contesto

Cliccando una scheda di riepilogo per tipo, gli altri filtri attivi vengono
mantenuti:

```php
url_safe('dgb_activities', array_merge(
    array_filter(['tab'=>'anomalie','atec'=>$anomF['tecnico'],
                  'adal'=>$anomF['dal'],'aal'=>$anomF['al']], fn($v)=>$v!==''),
    ['atipo'=>$r['tipo']]))
```

Un filtro che azzera gli altri costringe a reimpostarli e viene abbandonato dopo
il secondo tentativo.

## 6. Verifica per proprietà, non per campione

Il collaudo controlla proprietà che devono valere per costruzione, non singoli
valori:

- la somma dei conteggi per severità deve dare il totale (1.473 + 767 = 2.240);
- la somma per tipo deve dare lo stesso totale (1.459 + 781 = 2.240);
- filtrando per tecnico, il risultato deve contenere un solo nominativo;
- filtrando per intervallo, nessuna riga deve cadere fuori.

Una partizione che non somma al totale segnala che un filtro perde righe o le
conta due volte. È un controllo più forte della verifica di un caso noto, perché
non dipende dai dati presenti.
