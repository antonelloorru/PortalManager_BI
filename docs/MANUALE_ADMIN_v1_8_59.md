# Manuale Amministratore — v1.8.59

## Interventi sul contratto sbagliato

WTS-REP è un canone di reperibilità: remunera il fatto che qualcuno **sia
disponibile**, non l'intervento. Quando la chiamata arriva e il tecnico opera, il
modulo di intervento appartiene al contratto operativo collegato — WTS-CC o
WTS-CSS.

Un modulo imputato a una commessa WTS-REP è quindi un errore, e ora viene
segnalato in testa alla scheda **Anomalie orarie**.

## Le due segnalazioni sui vostri dati

| Rapporto | Data | Tecnico | Ore | Commessa errata | Suggerita |
|---|---|---|---|---|---|
| SODA_23_005716 | 02/07/2023 | Sozzi David | 1,00 | WTS_3092 | WTS_3053 |
| SODA_23_009943 | 26/09/2023 | Sozzi David | 1,00 | WTS_3092 | WTS_3053 |

La commessa suggerita è cercata fra quelle **dello stesso cliente** con linea
ammessa e attive alla data dell'intervento, preferendo le aperte.

**Attenzione alla colonna "Alternative"**: entrambi i casi hanno tre commesse
candidate, quindi il suggerimento è indicativo. Con una sola candidata sarebbe
quasi certo. La scelta va fatta da chi conosce l'intervento.

Le correzioni si fanno sul gestionale: alla successiva sincronizzazione la
segnalazione sparisce da sola.

## Correzione di quanto vi avevo detto

Nella versione precedente avevo segnalato come anomala l'assenza di ore sui
canoni WTS-REP, ipotizzando che le ore fossero imputate altrove e che il margine
del 95,6% fosse fittizio.

**Era sbagliato.** L'assenza di ore su WTS-REP è corretta: quelle commesse non
devono avere consuntivo. Le due ore presenti non erano il segnale di un problema
più grande, erano esattamente due errori.

Il margine dei canoni WTS-REP è quindi legittimo.

## Effetto sulle medie di effort

Le 54 commesse WTS-REP comparivano nelle medie come commesse a zero ore.
Escludendole, la media di ore per commessa a canone passa da **35,7 a 50,9** — una
differenza del 43%.

Se aveste usato il primo numero per dimensionare un team, avreste sottostimato di
oltre un terzo.

La vista `v_cm_redditivita_operativa` esclude le commesse di sola disponibilità
dall'effort; `v_cm_redditivita_commessa` le mantiene per i totali di ricavo,
perché il canone è incassato davvero. Due viste per due domande diverse.

## Se un altro canone segue la stessa regola

```sql
UPDATE cm_contract_models
   SET allows_reports = 0, operative_lines = 'WTS-CC,WTS-CSS'
 WHERE service_line = '<linea>';
```

Non serve una release: la regola è un attributo del modello contrattuale, non
codice.
