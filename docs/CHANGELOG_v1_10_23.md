# CHANGELOG — v1.10.23 (2026-10-07)

Software 1.10.23 · Schema 1.10.23 · Upgrade `sql/migration_v1_10_23.sql` (cumulativo da 1.10.06) · Plugin pm-ats **1.3.4**

## Testata «Lavora con noi» adattiva
L'immagine della sezione di testata si ridimensiona con la larghezza della finestra.

Due modalità in *Impostazioni › Aspetto › Sezione di testata*:
- **Immagine intera** (predefinita): l'immagine occupa la larghezza disponibile con altezza proporzionale, quindi non viene tagliata (1440 px → 480 px di altezza; 375 px → 125 px).
- **Fascia ritagliata**: altezza proporzionale alla larghezza, tra 150 e 560 px, con l'immagine a riempimento.

Altri adattamenti:
- Il titolo della testata ha un corpo fluido (60 → 24 px) e la larghezza della pagina non viene mai superata.
- Con un'immagine della Libreria media il browser scarica la dimensione adatta allo schermo (srcset), ad esempio la 768 px sul telefono e la 1536 px sul desktop.

## Immagine dalla Libreria media
Il pulsante **Scegli dalla Libreria media** apre la finestra media di WordPress, dove si carica un nuovo file o se ne sceglie uno esistente. Accanto ci sono l'anteprima e il comando **Rimuovi**. Resta possibile incollare un URL esterno.

## Riferimenti di creazione
«Ideatore del plugin per WordPress: Antonello Orrù © 2026 · componente PortalManager_BI» compare:
- in fondo a ogni tab delle impostazioni, insieme alla versione del plugin;
- nel piè di pagina delle schermate del plugin.

PortalManager: `WpAtsConfig::PLUGIN_RECOMMENDED` = 1.3.4.
