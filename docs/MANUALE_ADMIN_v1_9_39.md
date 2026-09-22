# Manuale Amministratore — v1.9.39

## Ordinativi Pratix
- Ogni ordinativo mostra ora il **Nome Commerciale** accanto al codice; con più
  commerciali compare il primo e «+N», con l'elenco completo nel tooltip.
- Il menu **Ordina per** consente di ordinare la lista per **Commerciale** o
  **Cliente** (A→Z), oltre ai criteri esistenti (importo, commesse, codice, data).

Il commerciale deriva dall'anagrafica commessa (`v_cm_pratix_righe.commerciale`):
se non compare, verificare l'esecuzione della migration SQL della release.
