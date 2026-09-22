# Manuale Amministratore — v1.8.66

## Cosa dicevano quei due errori

*«Illegal mix of collations»* significa che il database ha rifiutato un confronto
fra due colonne di testo con regole di ordinamento diverse.

Le tabelle d'appoggio usate per rimuovere i duplicati venivano create con la
regola predefinita del database (`utf8mb4_unicode_ci`), mentre
`cm_intervention_reports` usa `utf8mb4_general_ci`: è **l'unica tabella del
portale** con una regola diversa, probabilmente perché più vecchia di una
conversione o importata da un dump che portava con sé la propria.

## Il problema era più ampio di due errori

Riproducendo la vostra installazione, il consolidato produceva **sei** statement
in errore, non due: oltre alle due deduplica, anche la riconciliazione dei
rapporti con le attività DGB e le viste di marginalità.

Ogni collegamento fra `cm_intervention_reports` e un'altra tabella falliva. Gli
altri quattro non li avevate visti perché stanno più avanti nello script.

## Come l'ho corretto

Ho **allineato la collation della tabella** invece di correggere i singoli
collegamenti: l'anomalia è la tabella, e correggere i join uno per uno avrebbe
lasciato il problema per la prossima query.

L'allineamento è **in testa allo script**: gli statement che fallivano stanno a
metà del file, e una correzione in fondo non li avrebbe raggiunti. Il primo
tentativo la metteva in coda e gli errori restavano tutti e sei.

Le tabelle d'appoggio ora ereditano la regola dalla colonna che leggono, quindi
il problema non può ripresentarsi su nessuna installazione.

## Attenzione: i duplicati sono ancora nel database

Le migration v1.8.50 e v1.8.51 fallivano **proprio sulla cancellazione dei
duplicati**. Quindi non sono mai stati rimossi, e il controllo di unicità non è
mai stato applicato: la protezione oggetto di due release non è mai entrata in
funzione.

Questa migration fa entrambe le cose. **Le ore consuntivate possono calare**: sono
righe contate due volte da tre release.

Annotate i totali prima di aggiornare:

```sql
SELECT COUNT(*), ROUND(SUM(quantity_hours),2) FROM cm_intervention_reports;
```

Le ore possono diminuire, **mai aumentare**. Se aumentano, ripristinate il backup.

## Nota sui tempi

Il primo statement riscrive l'intera tabella dei rapporti per convertirne la
collation. Su decine di migliaia di righe richiede qualche minuto, con la tabella
bloccata in scrittura. Conviene farlo fuori orario.

## Un controllo da tenere

```sql
SELECT * FROM v_cm_collation_check;
```

Deve restituire zero righe. Elenca le colonne di collegamento con regola diversa
da quella del database: non è di per sé un errore, ma è la condizione che rende
possibile il conflitto. Vale la pena eseguirlo dopo ogni importazione di dati da
dump esterni, che è il modo in cui una tabella acquisisce una collation diversa.
