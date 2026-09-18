# Deployment — PortalManager v1.9.7

## 1. Contenuto

```
VERSION                          1.9.7
service_desk.php                 (ROOT)  assenze, grafici nei report
app/SdModel.php                  + 3 metodi
app/Version.php                  PM_VERSION = 1.9.7
gli altri file                   invariati da v1.9.6
sql/migration_v1_9_7.sql         solo bump di versione
sql/upgrade_1_7_56_to_1_9_7.sql  consolidato cumulativo
docs/                            questa documentazione
```

**Prerequisito**: v1.8.81 applicata (`v_cm_assenze_serie`).

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare **`service_desk.php` in ROOT** e i due file in `app\`.
3. SQL Runner: `sql/migration_v1_9_7.sql` oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

## 3. Verifica

**Service Desk**: nuovo riquadro **Assenze del team**.

Attesi sui vostri dati: **1.436,5 ore su 4 persone**, di cui 1.187,0 di ferie,
129,0 di permessi, 72,0 di malattia, 40,5 di recupero ore.

**Report di stampa**: entrambi contengono ora il grafico dell'andamento dei ticket
e il riquadro delle assenze con grafico mensile.

## 4. La voce «Altre»

Il totale delle assenze non coincide con la somma delle quattro voci: mancano
**8 ore**.

La causa è una riga con tutte le voci a zero e il totale valorizzato — Chiarini,
10/08/2026 — cioè un tipo di assenza che la v1.8.81 non aveva classificato.

L'ho esposta come **«Altre»** invece di lasciarla implicita: una differenza
silenziosa fra totale e somma fa sospettare un errore di calcolo dove c'è solo una
categoria in più.

**Se sapete di che tipo di assenza si tratta**, ditemelo e la classifico: basta
aggiungerla alle regole della v1.8.81.

## 5. Le visite non entrano nel totale

Le loro ore sono **già comprese nelle altre voci**: nel gestionale non esiste un
tipo dedicato e vengono riconosciute dalla descrizione.

Sono esposte a parte perché sono un'informazione, ma sommarle conterebbe due volte
le stesse ore.

## 6. I grafici in stampa

Gli SVG si stampano come **vettori**: escono a colori anche senza l'opzione
«Grafica di sfondo» del browser.

Quell'opzione serve per le **aree piene** dei riquadri in testa: senza, i numeri
restano leggibili ma i riquadri escono bianchi.

## 7. Le giornate

Calcolate su **8 ore**, la stessa convenzione della sezione Presidi (v1.8.96).
Se la vostra prassi usa un valore diverso, è una riga da cambiare.

## 8. Rollback

Ripristinare `service_desk.php` e `app/SdModel.php` dalla v1.9.6.

```sql
UPDATE app_settings SET setting_value='1.9.6'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
