# Deployment — PortalManager v1.9.42

1. File: `app/ItServiceModel.php` nella cartella `app/` del webroot.
2. SQL: `sql/migration_v1_9_42.sql` (solo allineamento versione).
   Da versione incerta: `sql/upgrade_1_9_40_to_1_9_42.sql`.

Via system_console.php: Aggiornamento (ZIP) → `PortalManager_v1_9_42.zip` → Analizza → Applica.
Via PowerShell: `Expand-Archive ... -Force` (preserva `app/`) + SQL Runner. Stop+Start Apache, Ctrl+F5.

## Verifica post-deploy
| Passo | Esito atteso |
|---|---|
| Relazione di Servizio IT → periodo ampio | il Riepilogo per Codice Contratto mostra le righe |
| Etichetta contratto | codice reale, oppure project_code, oppure "Contratto #id" |
| Dettaglio per commessa | righe presenti |

## Nota (dato a monte)
La causa a valle è il fix del join; la causa a monte resta la **mancata
sincronizzazione di `dgb_forms_contract`**. Ripristinando l'import DGB dei contratti,
le etichette useranno i codici/denominazioni reali senza ulteriori modifiche.
