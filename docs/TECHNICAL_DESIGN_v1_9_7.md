# Technical Design — PortalManager v1.9.7

## 1. Le assenze non richiedono il ponte dei nomi

`v_cm_assenze_serie` usa `Nome Cognome`, la stessa forma di `cm_sd_messages`. Il
join su `v_cm_sd_team.nome` è diretto.

È il caso opposto ai moduli di intervento (v1.8.87), che usano `Cognome Nome` e
hanno richiesto `v_cm_sd_nome_moduli`. Vale la pena verificare la forma prima di
assumerla: qui il ponte avrebbe aggiunto un join inutile, là la sua assenza
avrebbe dato zero ore.

## 2. `altre` come differenza esposta

```sql
ROUND(SUM(totale_assenze) - SUM(ferie + permessi + recuperi + malattia), 2) AS altre
```

Il collaudo ha trovato 8 ore di scarto fra il totale e la somma delle voci.

Tre risposte possibili: ignorarlo, correggere il totale, o esporre la differenza.

Ignorarlo avrebbe lasciato una tabella in cui i numeri non tornano, e chi la guarda
conclude che il calcolo è sbagliato. Correggere il totale — sommare le quattro
voci invece di leggere `totale_assenze` — avrebbe fatto sparire 8 ore di assenza
reale.

La differenza esposta dice che esiste una categoria non classificata, e quanto
pesa. Se cresce, è un segnale che le regole della v1.8.81 vanno estese.

## 3. Le visite fuori dal totale, ancora

La v1.8.81 aveva accertato che le visite non hanno un tipo dedicato nel
gestionale: sono riconosciute dalla descrizione fra impegni di altri tipi, e le
loro ore sono già contate nelle rispettive voci.

Esporle è utile — quantifica un fenomeno che altrimenti resta invisibile — ma
sommarle sarebbe un doppio conteggio.

La nota lo dichiara in ogni punto in cui compaiono, perché una colonna in una
tabella di totali viene istintivamente sommata.

## 4. `$svgBarre` come closure condivisa

I due report — generale e personale — usano lo stesso grafico con serie diverse.

Definirla una volta prima del blocco di stampa evita di duplicare cinquanta righe
di generazione SVG, e garantisce che i due report abbiano grafici identici nella
resa.

La firma prende le serie e i colori come parametri: la stessa funzione disegna
l'andamento dei ticket per classe di gestione e le assenze per tipo.

## 5. Il grafico non si disegna sotto due righe

```php
if (count($dati) < 2) return '';
```

Un grafico a barre con un solo punto non mostra un andamento: mostra un
rettangolo. Occupa spazio e non dice nulla che il numero accanto non dica meglio.

## 6. L'apostrofo che ha rotto la query

Un commento SQL dentro la stringa PHP conteneva `cio'`. L'apostrofo ha chiuso la
stringa, e il resto del commento è diventato codice PHP non valido.

`php -l` l'ha rilevato — è un errore di sintassi, non di runtime — ma è il genere
di difetto che un commento in italiano dentro una query rende facile.

La regola pratica: i commenti esplicativi vanno **fuori** dalla stringa SQL, nel
codice PHP che la circonda. Dentro la query, solo commenti brevi e senza
apostrofi.

## 7. Le giornate su 8 ore

Stessa convenzione della sezione Presidi (v1.8.96), dove `presidio_giornata_ore` è
un parametro in tabella.

Qui è cablata a 8. È un'incoerenza minore ma reale: se un domani la convenzione
cambiasse, andrebbe cambiata in due punti. Dichiarata nel deployment.
