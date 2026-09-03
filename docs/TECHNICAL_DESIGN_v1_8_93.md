# Technical Design — PortalManager v1.8.93

## 1. Il dato c'era già

`exec_company_id` è popolato da `DatasetSync` tramite `company_from`, che risolve
il prefisso del codice commessa contro l'anagrafica delle società.

La logica esisteva, la colonna era piena su 1.062 righe su 1.062, e nessuna vista
la leggeva. Aggiungere la dimensione è costato un `LEFT JOIN` e una colonna nel
`SELECT`.

È il caso opposto a quello dei chilometri (v1.8.89), dove la struttura c'è e il
dato no. Vale la pena, prima di costruire una derivazione, verificare se qualcuno
l'abbia già costruita.

## 2. Nome contro prefisso

Un `SUBSTRING_INDEX(project_code, '_', 1)` avrebbe dato lo stesso raggruppamento
senza join. Due ragioni contro.

**Semantica**: `WTS` è una convenzione di codifica, `WETECH'S SPA SB` è la società.
In un report destinato a chi non conosce i codici, il primo richiede una legenda.

**Duplicazione**: la risoluzione prefisso → azienda esiste già in `PrefixResolver`.
Riscriverla in SQL significa avere due implementazioni della stessa regola, che
divergono al primo cambio di convenzione — e nessuno saprebbe quale delle due è
quella giusta.

## 3. Il riquadro condizionato

```php
<?php if (count($aziende) > 1): ?>
```

Nel Service Desk le aziende sono una sola: tutti i moduli stanno su commesse
WETECH'S.

Una tabella con una riga che ripete il totale già mostrato sopra occupa spazio e
non aggiunge nulla. Nasconderla non perde informazione, e il riquadro comparirà da
solo se un domani il Service Desk lavorasse su commesse di altre società.

L'**export invece include sempre** il foglio: in un file di dati la riga singola è
un fatto — «il Service Desk opera solo su Wetechs» — mentre a video sarebbe
rumore. Il criterio non è lo stesso perché i due contesti hanno costi diversi:
sullo schermo lo spazio è conteso, in un foglio no.

## 4. Palette separata per le aziende

```php
$COLAZ = ['#2563eb','#16a34a','#f59e0b','#7c3aed','#dc2626','#0891b2'];
```

Distinta da `$colClasse`, che colora le classi di gestione dei ticket.

Riusare gli stessi colori avrebbe suggerito una relazione fra dimensioni che non
ne hanno: il verde delle classi significa «risolto dal Service Desk», e sulle
aziende non significherebbe niente di analogo.

## 5. `(non attribuita)` invece di NULL

```sql
COALESCE(az.`name`, '(non attribuita)') AS azienda
```

Un NULL in un raggruppamento produce una riga senza etichetta, che finisce in
fondo all'elenco e si legge come un difetto di visualizzazione.

Un'etichetta esplicita dice che quella categoria esiste ed è vuota di attribuzione:
è un'informazione, non un buco.

Sui dati attuali non compare — tutte le commesse hanno l'azienda — ma la vista
deve reggere il caso in cui una nuova commessa arrivi con un prefisso non
riconosciuto.
