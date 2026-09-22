# Manuale Utente — v1.8.61

## Il conto della commessa

Il portale importa ora tutte le voci economiche che alimentano o addebitano una
commessa:

**Alimentano** — cioè aggiungono valore:
- **Ordine cliente**: l'ordine che il cliente emette
- **Riporto da contratto precedente**: il saldo che arriva dal contratto
  precedente dello stesso tipo e cliente. Può anche essere negativo, se il
  contratto precedente si chiudeva a debito
- **Storno**: una rettifica che annulla un addebito errato

**Addebitano** — cioè sottraggono valore:
- **Costo Direzione Commerciale**
- **Acquisto Beni e Servizi**: materiale o servizi acquistati per la commessa

A queste si aggiunge il **costo delle ore** lavorate, che resta contato a parte.

## Il saldo

Il saldo finale di una commessa è: quello che l'ha alimentata, meno gli addebiti,
meno il costo delle ore.

Le tre voci restano distinte perché raccontano cose diverse: una commessa può
essere in perdita perché si è comprato troppo materiale, oppure perché ci sono
volute più ore del previsto. Sono problemi diversi.

## Attenzione ai saldi negativi

Un saldo negativo su una commessa **interna** (le linee NV_) è normale: quelle
commesse non hanno ricavo per definizione, sono lavoro che facciamo per noi.

Un saldo negativo su una commessa **a cliente** merita invece attenzione.
