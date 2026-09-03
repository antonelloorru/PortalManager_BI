# Manuale Utente — v1.8.46

## I dati delle commesse si aggiornano dal gestionale

Nel menu Gestione Commesse è comparsa la voce **Sincronizzazione gestionale**.
Serve ad allineare i dati del portale a quelli del gestionale: commesse, rapporti
di intervento e anagrafica dei professionisti.

Per chi consulta le pagine, questo significa che codici, clienti, valori,
margini, ore e stati sono quelli del gestionale, aggiornati all'ultima
sincronizzazione.

## Chi la esegue

Serve il permesso di amministrazione delle commesse, lo stesso richiesto per
l'import. Se notate dati non aggiornati, segnalatelo a chi amministra il portale
indicando quale commessa o quale rapporto vi sembra disallineato.

## Che cosa non compare

Le commesse eliminate sul gestionale non vengono importate.

I rapporti di intervento compaiono solo quando l'attività è **approvata, chiusa o
completata**. Un intervento ancora in bozza o in corso non si vede nel portale:
comparirà appena verrà chiuso sul gestionale.

## Interventi con più tecnici

Se un intervento è stato svolto da due persone, nel portale trovate due righe, una
per tecnico, ciascuna con le proprie ore. Il codice del rapporto in quel caso ha
un suffisso numerico che distingue le due righe.

## I dati vengono modificati sul gestionale?

No. Il portale legge soltanto. Le correzioni vanno fatte sul gestionale e alla
sincronizzazione successiva arriveranno anche qui.

## Le commesse inserite a mano

Le commesse create direttamente nel portale, non presenti nel gestionale, non
vengono toccate: restano dove sono.
