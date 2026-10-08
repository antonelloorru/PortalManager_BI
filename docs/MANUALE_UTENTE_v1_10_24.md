# MANUALE UTENTE — v1.10.24 · Ricerca

*Gestione Commesse › Ricerca*

1. **Tutto il database**: scrivi in «Cerca ovunque» (o usa commessa, cliente, persona, società, periodo) e premi Cerca. Ogni riquadro indica quante righe corrispondono in quell'archivio e ne mostra alcune. Con «Apri» passi all'archivio mantenendo i filtri.
2. **Un archivio** (Commesse, Moduli di intervento, Pianificazione, Impegni, Operazioni, Team, Professionisti, Anagrafica tecnica, Ordinativi Pratix, Attività DGB, Progetti PRJ, Timesheet, Ticket SOC):
   - **filtri per colonna**: scrivi nella riga sotto le intestazioni e premi Invio;
   - **ordinamento**: clic sull'intestazione; un secondo clic inverte l'ordine;
   - **Colonne**: scegli quali colonne vedere ed esportare;
   - **totali**: la riga in fondo somma ore, giorni e importi di tutto il risultato, non solo della pagina;
   - i codici commessa in blu aprono la scheda della commessa.
3. **Sintassi dei filtri di colonna**:
   - testo: `rete` contiene · `rete|switch` uno dei due · `=ACM` uguale · `^WTS` inizia con · `!test` non contiene;
   - numeri: `>=8` · `<0` · `10..20`;
   - date: `2026` · `2026-03` · `03/2026` · `15/03/2026` · `>=2026-01` · `2026-01..2026-03`;
   - sì/no: `sì` · `no`;
   - per tutte: `=` vuoto · `!=` valorizzato.
   Un filtro non valido viene evidenziato in rosso e ignorato.
4. **Esporta i risultati**: CSV, XLSX, DOCX o PDF, con le colonne, i filtri e l'ordinamento a schermo. DOCX e PDF arrivano fino a 3.000 righe, XLSX e CSV fino a 50.000.
5. Importi e costi compaiono solo se il tuo ruolo è abilitato a vederli.
