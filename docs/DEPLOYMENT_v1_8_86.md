# Deployment — PortalManager v1.8.86

## 1. Contenuto

```
VERSION                          1.8.86
service_desk.php                 (ROOT)  scheda e confronto
app/SdModel.php                  + 5 metodi per la scheda
app/Version.php                  PM_VERSION = 1.8.86
gli altri file                   invariati da v1.8.85
sql/migration_v1_8_86.sql        4 viste
sql/upgrade_1_7_56_to_1_8_86.sql consolidato cumulativo (581 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare **`service_desk.php` in ROOT** e i due file in `app\`.
3. SQL Runner: `sql/migration_v1_8_86.sql` (da v1.8.85) oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

Nessuna risincronizzazione: le viste leggono i dati già presenti.

## 3. Verifica

**Gestione Commesse → Service Desk**: sotto i quattro indicatori compare la
tabella **I componenti del Service Desk**.

| Componente | Presi in carico | Escalation | 1ª risposta |
|---|---|---|---|
| Enrico Mancini | 613 | 5,1% | 9,2 h |
| Sebastiano Chiarini | 520 | 6,2% | 4,9 h |
| Emanuele Bressi | 278 | 10,8% | 6,1 h |
| Greta Ferrante | 49 | 4,1% | 4,1 h |

**Clic su un nome** apre la scheda completa. Il pulsante *Chiudi scheda* torna
alla vista d'insieme.

Il nome è cliccabile anche nella tabella *Operatività per tecnico* in fondo alla
pagina.

## 4. Come leggere la scheda

La scheda ha **due gruppi separati** e non vanno confusi:

| Gruppo | Cosa comprende | Attribuibile |
|---|---|---|
| **Ticket presi in carico** | quelli di cui ha scritto la prima risposta | **sì** |
| **Attività complessiva** | ogni messaggio, anche su ticket altrui | no |

Contare i messaggi misura quanto una persona scrive, non quanto risolve.

## 5. Il confronto con i pari livello

Ogni indicatore di esito riporta la media degli altri componenti, perché un valore
isolato non dice se sia alto o basso.

**Il tempo di prima risposta va letto insieme al volume**: chi prende in carico più
ticket ha naturalmente tempi più alti. Mancini ha 9,2 h contro una media di 5,0,
ma prende in carico il doppio dei ticket di Bressi.

Il tasso di escalation compare in rosso quando supera del 40% la media dei pari:
è una segnalazione da verificare, non un giudizio.

## 6. Profili diversi, non rendimenti diversi

Ferrante ha 5 code contro le 11 degli altri e scrive più note che risposte
(rapporto 0,6 contro 1,7–3,9). È un ruolo diverso.

Le misure sono affiancate proprio per questo: ridurle a una classifica
confronterebbe persone che fanno lavori diversi.

## 7. I dati della tabella di confronto

Sono sull'**intero archivio**, non sul periodo filtrato — è dichiarato sotto la
tabella. Nella scheda invece compaiono entrambi: il totale e il periodo
selezionato.

## 8. Rollback

```sql
DROP VIEW IF EXISTS v_cm_sd_tecnico_coda;
DROP VIEW IF EXISTS v_cm_sd_tecnico_mese;
DROP VIEW IF EXISTS v_cm_sd_scheda_tecnico;
DROP VIEW IF EXISTS v_cm_sd_presa_carico;
UPDATE app_settings SET setting_value='1.8.85'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Ripristinare `service_desk.php` e `app/SdModel.php` dalla v1.8.85.
