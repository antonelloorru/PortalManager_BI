# Technical Design — PortalManager v1.8.55

## 1. La soglia non è arbitraria

Il problema di un rilevamento automatico è dove tracciare il confine. Una soglia
troppo bassa marca come reperibile chiunque abbia lavorato una sera; una troppo
alta esclude chi lo è davvero.

I dati rispondono da soli. La distribuzione delle giornate in reperibilità per
tecnico è **bimodale**: 124 tecnici sopra le 10 giornate con media 252, e 14 sotto
le 4. In mezzo, fra 4 e 9, ci sono 5 tecnici: una zona quasi vuota.

Una distribuzione con due mode separate da una valle indica due popolazioni
distinte — chi la reperibilità la fa per ruolo e chi vi è capitato. La soglia
naturale è nella valle, e 5 giornate ci cade.

Se la distribuzione fosse stata continua, qualunque soglia sarebbe stata
arbitraria e andava dichiarata tale. Qui non lo è, ed è la ragione per cui la
misura è riportata nel commento della migration: chi la rivedrà deve poter
verificare che la valle esista ancora.

## 2. La finestra temporale

Dodici mesi. Senza finestra, un tecnico reperibile nel 2023 e passato ad altro
ruolo risulterebbe reperibile per sempre: il consuntivo è cumulativo e non
dimentica.

Dodici mesi coprono un ciclo completo di stagionalità — chiusure estive, picchi di
fine anno — senza trascinare organizzazioni superate.

## 3. Due ponti verso l'anagrafica

Le ore stanno su `dgb_operator`, i profili su `cm_tech_profiles` che punta a
`employees` o `cm_professionals`. I collegamenti esistevano già:

```
dgb_operator_map.employee_id        -> tecnico interno   (216 righe)
cm_professionals.source_operator_id -> professionista esterno
```

**95 tecnici su 103 risultano su entrambe le strade.** È fisiologico: un
dipendente censito anche come professionista esterno nell'anagrafica del
gestionale. Poiché `cm_tech_profiles` ha vincoli UNIQUE su entrambe le colonne e
ne ammette una sola valorizzata, serve una precedenza.

L'identità interna vince: un dipendente è tale prima di essere un fornitore, e il
profilo interno è quello che le altre viste useranno per collegare unità
organizzativa e seniority. Verificato: 0 profili con entrambe le colonne piene.

## 4. Proporre invece di applicare

Il flag non cambia da solo. È la differenza fra un sistema che assiste una
decisione e uno che la prende al posto di chi ha le informazioni.

Il consuntivo vede solo gli interventi **eseguiti**. Un tecnico di turno che non
riceve chiamate non compare, ed è comunque reperibile — anzi, è il caso migliore.
Un'applicazione automatica gli toglierebbe il flag alla prima sincronizzazione
dopo un mese tranquillo.

Da qui anche la regola che **il flag non viene mai rimosso**: si può proporre di
attivarlo, mai di disattivarlo. L'assenza di evidenze non è evidenza di assenza.

Lo stesso vale per H24, che viene alzato ma mai abbassato:

```php
$newH24 = max((int)$prev['on_call_h24'], $wantH24);
```

## 5. Idempotenza

Rieseguire la funzione non produce effetti. Verificato: seconda esecuzione con
0 creati, 0 aggiornati, 103 già corretti.

È la proprietà che rende la funzione utilizzabile senza timore: chi non è sicuro
di averla già lanciata può rilanciarla.

## 6. Creazione dei profili

Nessuno dei 111 tecnici aveva un profilo, perché l'Anagrafica Tecnica è recente e
non ancora popolata. Senza la creazione, la funzione non avrebbe potuto applicare
nulla e sarebbe risultata inutile alla prima esecuzione — quella in cui serve di
più.

L'opzione è disattivabile: chi vuole solo aggiornare i profili esistenti, senza
crearne di nuovi, toglie la spunta. Verificato: con l'opzione disattivata, 0
creati e 103 saltati.

## 7. Perché viste e non una tabella di stato

Le evidenze si ricalcolano a ogni apertura della pagina. Una tabella di appoggio
andrebbe rigenerata dopo ogni sincronizzazione, e nell'intervallo mostrerebbe
proposte basate su dati vecchi.

Il costo è un'aggregazione su `v_dgb_ore_ripartite` filtrata sugli ultimi dodici
mesi, con l'elenco limitato a 200 righe.
