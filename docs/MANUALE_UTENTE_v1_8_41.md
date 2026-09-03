# Manuale Utente — Gestione Commesse (v1.8.41)

## Che cosa cambia per chi usa il portale

Fino a ieri l'elenco delle commesse mostrava voci come `DGB-77` — `Contratto
DogoBit #77`, con le colonne dei valori vuote. Da questa versione compaiono i
codici veri del gestionale, per esempio `WTS_3016`, con nome del cliente, importi,
margini e stato economico al loro posto.

Cambiano soltanto i dati: la pagina, i filtri e i pulsanti di esportazione sono
quelli di prima.

## Dove ritrovare le stesse commesse

Le ore già registrate non sono andate perse: erano agganciate al codice provvisorio
e ora si trovano sulla commessa reale corrispondente. Se cercavate un lavoro sotto
`DGB-77`, adesso è sotto `WTS_3016` con lo stesso identico storico.

Le pagine collegate seguono automaticamente: Gantt commesse mostra le barre di
pianificazione, il Carico risorse indica i codici reali nel dettaglio, il Timesheet
propone le commesse con il nome corretto.

## Due voci ancora con il vecchio codice

In fondo all'elenco possono comparire un paio di commesse con codice `DGB-`
seguito da un numero. Sono contratti aperti di recente, non ancora presenti nel
file del gestionale: prenderanno il codice definitivo al prossimo aggiornamento
dei dati. Nel frattempo funzionano normalmente.

## Se qualcosa non torna

**Vedo ancora i vecchi codici.** Aggiornate la pagina con Ctrl+F5. Se persistono,
l'aggiornamento potrebbe non essere ancora stato applicato: segnalatelo a chi
amministra il portale.

**Non trovo una commessa che c'era.** Provate a cercarla per nome o per cliente nel
campo Cerca, invece che per codice: quasi certamente ha cambiato codice passando da
quello provvisorio a quello reale.
