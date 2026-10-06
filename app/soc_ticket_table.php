<?php
/**
 * app/soc_ticket_table.php — v1.10.06
 * Tabella dei ticket del Service SOC (elenco e «da presidiare»). Riceve gli helper di formattazione della pagina.
 */
declare(strict_types=1);

function soc_ticket_table(array $rows, callable $qs, callable $pill, callable $colStato, callable $dt, callable $n, callable $n1, bool $compact): void
{
    $h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    if (!$rows) { echo '<p style="font-size:12px;color:var(--muted);margin:0">Nessun ticket.</p>'; return; }
    echo '<table class="data-table" style="width:100%;font-size:12px"><thead><tr><th>Ticket</th><th>Titolo</th><th>Cliente</th><th>Categoria</th><th>Incaricato</th>'
       . '<th>Aperto</th><th>Ultimo evento</th><th>Stato</th>' . ($compact ? '<th style="text-align:right">Senza risposta da</th>' : '<th>Esito</th><th style="text-align:right">Eventi</th><th style="text-align:right">Risposta media</th><th style="text-align:right">Ore moduli</th><th>Commesse PM</th>')
       . '</tr></thead><tbody>';
    foreach ($rows as $t) {
        $late = (int)($t['ore_da_ultimo'] ?? 0);
        echo '<tr><td style="white-space:nowrap"><a href="' . $qs(['ticket' => $t['ticket_code'], 'tab' => 'ticket']) . '">' . $h($t['ticket_code']) . '</a></td>'
           . '<td>' . $h(mb_strimwidth((string)$t['title'], 0, 80, '…')) . '</td><td>' . $h($t['client_name']) . '</td><td>' . $h($t['category']) . '</td><td>' . $h($t['assignee_name']) . '</td>'
           . '<td style="white-space:nowrap">' . $dt($t['opened_at']) . '</td><td style="white-space:nowrap">' . $dt($t['last_event_at']) . '</td>'
           . '<td>' . $pill($t['status_now'] ?? '—', $colStato($t['status_now'] ?? '')) . '</td>';
        if ($compact) {
            echo '<td style="text-align:right;white-space:nowrap">' . ($late >= 48 ? $n($late / 24) . ' gg' : $n($late) . ' h') . '</td>';
        } else {
            echo '<td>' . $h($t['resolution'] ?? '') . '</td><td style="text-align:right">' . $n($t['n_events']) . '</td>'
               . '<td style="text-align:right">' . ($t['avg_reply_min'] === null ? '—' : $n1($t['avg_reply_min'] / 60) . ' h') . '</td>'
               . '<td style="text-align:right">' . ((float)$t['ore'] > 0 ? $n1($t['ore']) : '—') . '</td><td style="font-size:11px">' . $h($t['commesse'] ?? '') . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
}
