# Manuale Amministratore — v1.8.49

## Analisi del database sorgente

In **Gestione Commesse → Sincronizzazione gestionale** il primo riquadro è ora
*Analisi del database sorgente*, con il pulsante **Analizza tutte le tabelle**.

Prima la sincronizzazione guardava solo le nove tabelle che le servono per
leggere i dati. Funzionava, ma non permetteva di accorgersi di nulla: se il
fornitore rinominava una tabella, lo si scopriva quando l'import andava in
errore.

L'analisi esamina ora l'intero schema e riporta:

- **Oggetti totali**, divisi fra tabelle e viste
- **Usate dai dataset**: quelle che la sincronizzazione legge
- **Mancanti**: richieste ma non presenti nella sorgente

Il numero in *Mancanti* è quello da guardare. Se è diverso da zero, la
sincronizzazione dei dataset interessati fallirebbe, e il messaggio elenca quali
tabelle cercare — tipicamente sono state rinominate o spostate in un altro schema.

Espandendo *Elenco completo degli oggetti* si vedono tutti, con colonne, righe
stimate e l'indicazione di quali sono usati (evidenziati in verde).

L'analisi legge dai cataloghi di sistema e **non tocca i dati**: si può eseguire
in qualunque momento.

### Le viste del gestionale

Sulla sorgente attuale l'analisi trova **111 oggetti: 102 tabelle e 9 viste**.

Le viste sono una novità: nel dump precedente non c'erano. Sono estrazioni già
pronte lato gestionale, e alcune sono vicine ai tracciati già in uso —
`v_contract_export_list` ha tredici colonne nel formato dell'export commesse.

Compaiono fra gli oggetti non utilizzati proprio per questo: sono candidate
naturali per nuovi dataset di sincronizzazione. Valutarle è materia di una
prossima release, ma da ora sono visibili invece che ignote.

## Attività & Rendicontazione DGB

La pagina esisteva e funzionava, ma non aveva una voce di menu: era raggiungibile
solo conoscendone l'indirizzo. Ora è in **Gestione Commesse → Attività &
Rendicontazione DGB**.

I permessi sono allineati a quelli di Import Commesse DB: chi poteva importare le
commesse vede anche questa pagina.

## Nota sull'aggiornamento

Questo pacchetto **sostituisce** i file di menu della v1.8.48. Se aveste
installato la v1.8.48 e vi accorgeste che la voce "Sincronizzazione gestionale" è
scomparsa, è questo il motivo: applicando la v1.8.49 torna al suo posto.
