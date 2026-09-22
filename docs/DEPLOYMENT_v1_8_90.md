# Deployment — PortalManager v1.8.90

Release **solo applicativa**: nessuna variazione di schema né di dati.

## 1. Contenuto

```
VERSION                          1.8.90
it_service.php                   (ROOT)  NUOVO — la pagina
app/it_service_print.php         NUOVO — report di stampa a colori
app/MenuManager.php              voce di menu
app/Router.php                   slug della pagina
app/Version.php                  PM_VERSION = 1.8.90
gli altri file                   invariati da v1.8.89
sql/migration_v1_8_90.sql        solo bump di versione
sql/upgrade_1_7_56_to_1_8_90.sql consolidato cumulativo (596 statement)
docs/                            questa documentazione
```

**Prerequisito**: v1.8.89 applicata (viste `v_cm_it_servizio` e collegate).

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare **`it_service.php` in ROOT** e i quattro file in `app\`.
3. SQL Runner: `sql/migration_v1_8_90.sql` (da v1.8.89) oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

## 3. Verifica

**Gestione Commesse → Relazione di Servizio IT**. Sui dati del 2026:

| Indicatore | Atteso |
|---|---|
| Interventi | ~16.855 |
| Giornate-uomo | ~9.401 |
| Ore | ~71.835 (7,6 h/giornata) |
| Ore a ricavo | ~73,8% |

Sotto: quattro grafici a barre, l'andamento mensile, e la tabella di dettaglio.

## 4. I filtri

Ogni menu è a **selezione multipla**: tenere premuto Ctrl (Cmd su Mac) per
scegliere più valori.

- più valori sulla **stessa** dimensione si sommano
- dimensioni **diverse** si restringono

**Raggruppa per** accetta anch'esso più dimensioni: `settore × modalità × fascia
oraria` produce una riga per ogni combinazione presente.

## 5. Export e stampa

**XLSX + pivot**: sei fogli, fra cui la matrice incaricato × linea di servizio
(2.033 celle) già pronta per un grafico pivot in Excel.

**Report di stampa**: si apre in una scheda nuova, A4 orizzontale, con i grafici a
colori e i filtri applicati riportati in testa.

**Per stampare i colori** attivare *«Grafica di sfondo»* nelle opzioni di stampa
del browser: è disattivata per impostazione predefinita, e senza di essa i grafici
escono in bianco e nero. È una preferenza del browser che il server non può
forzare.

## 6. Il settore tecnologico

Dipende dalle assegnazioni in *Unità Organizzative Tecniche*: sul database di
prova risulta un solo settore perché nessun profilo ha l'unità.

**Sul vostro server ne avete 27 assegnati su 104 profili**: più ne completate, più
la ripartizione per settore diventa significativa.

## 7. I chilometri

Restano a zero finché non ci sono gli indirizzi. La pagina lo dichiara con un
avviso, e mostra le ore di viaggio che misurano lo stesso fenomeno.

Per attivarli, i tre passi del deployment v1.8.89: sincronizzare gli indirizzi dei
clienti, completare quelli delle sedi, popolare `cm_it_distances`.

## 8. Rollback

Rimuovere `it_service.php` e `app/it_service_print.php`, ripristinare
`MenuManager.php`, `Router.php` e `Version.php`, poi:

```sql
UPDATE app_settings SET setting_value='1.8.89'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Le viste della v1.8.89 restano e sono interrogabili da SQL.
