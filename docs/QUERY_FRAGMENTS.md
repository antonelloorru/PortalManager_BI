# Frammenti query aggiornate + adattamento UI (da Pratix)

Chiave di join in tutti i frammenti: `order_code = Codice` (Excel) = `cm_pratix_ext.order_code`.

## A) Ordini Pratix — `pratix_orders.php` (già applicato in questo pacchetto)

### Query sorgente (LEFT JOIN)
Sostituita la vista sorgente con quella arricchita:
```php
// prima:  "SELECT o.* FROM `v_cm_pratix_ordinativi` o ..."
// dopo:
"SELECT o.* FROM `v_cm_pratix_ordinativi_ext` o ..."
```
`v_cm_pratix_ordinativi_ext` = `v_cm_pratix_ordinativi` LEFT JOIN `cm_pratix_ext`
su `order_code`, esposti come `px_cliente_effettivo`, `px_totale`, … (12 campi).

### UI (per ogni card ordinativo) — 12 colonne "(da Pratix)"
Blocco inserito prima delle "commesse collegate" (vedi pratix_orders.php), con
etichette esplicite: `Cliente Effettivo (da Pratix)`, `Cliente di Fatturazione (da Pratix)`,
`Progetto (da Pratix)`, `Descrizione (da Pratix)`, `Stato (da Pratix)`, `Azienda (da Pratix)`,
`Numero Documento (da Pratix)`, `Tipologia (da Pratix)`, `Totale (da Pratix)`,
`Firma Tecnica (da Pratix)`, `Firma Commerciale (da Pratix)`, `Linea di Business (da Pratix)`.

## B) Commesse / Progetti — `manage_projects.php` (frammento da integrare)

### 1) Recupero dati Pratix per commessa (dopo il caricamento di `$rows`)
```php
// Arricchimento Pratix: mappa project_code -> dati Pratix
$pxByProject = [];
foreach ($pdo->query("SELECT * FROM `v_cm_pratix_commessa_ext`")->fetchAll(PDO::FETCH_ASSOC) as $px) {
    $pxByProject[(string)$px['project_code']] = $px;
}
```
La vista `v_cm_pratix_commessa_ext` lega la commessa (`cm_projects.project_code`) al
Pratix tramite l'`order_code` presente nelle righe pratix (`v_cm_pratix_righe`),
LEFT JOIN su `cm_pratix_ext` (`order_code = Codice`).

### 2) Intestazioni tabella — 12 colonne "(da Pratix)"
```php
<?php foreach ([
  'Cliente Effettivo (da Pratix)','Cliente di Fatturazione (da Pratix)','Progetto (da Pratix)',
  'Descrizione (da Pratix)','Stato (da Pratix)','Azienda (da Pratix)','Numero Documento (da Pratix)',
  'Tipologia (da Pratix)','Totale (da Pratix)','Firma Tecnica (da Pratix)',
  'Firma Commerciale (da Pratix)','Linea di Business (da Pratix)',
] as $thPx): ?><th><?=h($thPx)?></th><?php endforeach; ?>
```

### 3) Celle (nel loop di riga `$r`)
```php
<?php $px = $pxByProject[(string)($r['project_code'] ?? '')] ?? []; ?>
<td><?=h($px['px_cliente_effettivo']    ?? '—')?></td>
<td><?=h($px['px_cliente_fatturazione'] ?? '—')?></td>
<td><?=h($px['px_progetto']             ?? '—')?></td>
<td><?=h($px['px_descrizione']          ?? '—')?></td>
<td><?=h($px['px_stato']                ?? '—')?></td>
<td><?=h($px['px_azienda']              ?? '—')?></td>
<td><?=h($px['px_numero_documento']     ?? '—')?></td>
<td><?=h($px['px_tipologia']            ?? '—')?></td>
<td style="text-align:right"><?= isset($px['px_totale']) && $px['px_totale']!==null ? number_format((float)$px['px_totale'],2,',','.').' €' : '—' ?></td>
<td><?=h($px['px_firma_tecnica']        ?? '—')?></td>
<td><?=h($px['px_firma_commerciale']    ?? '—')?></td>
<td><?=h($px['px_linea_business']       ?? '—')?></td>
```

### 4) (Opzionale) export "Lista commesse": aggiungi al `$STD_HEADERS`
```php
'Cliente Effettivo (da Pratix)','Cliente di Fatturazione (da Pratix)','Progetto (da Pratix)',
'Descrizione (da Pratix)','Stato (da Pratix)','Azienda (da Pratix)','Numero Documento (da Pratix)',
'Tipologia (da Pratix)','Totale (da Pratix)','Firma Tecnica (da Pratix)',
'Firma Commerciale (da Pratix)','Linea di Business (da Pratix)',
```
e in `$rowToStd` accoda i 12 valori da `$pxByProject[$r['project_code']]`.
