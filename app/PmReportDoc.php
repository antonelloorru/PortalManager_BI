<?php
/**
 * PortalManager — app/PmReportDoc.php  (v1.10.13)
 * Adattatore con l'interfaccia di DocxWriter (heading, paragraph, meta, note, box, kpi, table, bars, stackedbars,
 * pageBreak, spacer, download) che registra il contenuto in un PmReport: un report scritto per il DOCX
 * (es. Relazione di Servizio IT) esce identico in DOCX, PDF, XLSX e CSV senza duplicarne il codice.
 * Le tabelle prendono come nome (foglio XLSX, sezione CSV) l'ultimo titolo incontrato.
 */
declare(strict_types=1);

require_once __DIR__ . '/PmReport.php';

final class PmReportDoc
{
    private PmReport $r;
    private string $last = '';
    private string $sect = '';      // ultimo titolo di livello 1 (sezione)
    private int $lvl = 0;
    private int $n = 0;

    public function __construct(string $title = '', private string $fmt = 'docx')
    {
        $this->r = new PmReport($title);
        $this->last = $title;
    }

    public function report(): PmReport { return $this->r; }

    public function heading(string $text, int $level = 1): self
    {
        $this->r->heading($text, $level); $this->last = $text; $this->lvl = $level;
        if ($level <= 1) $this->sect = $text;
        return $this;
    }
    public function paragraph(string $text, array $o = []): self { $this->r->para($text, $o); return $this; }
    public function meta(string $text): self { $this->r->meta($text); return $this; }
    public function note(string $text): self { $this->r->note($text); return $this; }
    public function box(string $text, string $border = '2563EB', string $fill = 'F1F5F9'): self { $this->r->box($text); return $this; }
    public function spacer(): self { return $this; }
    public function pageBreak(): self { if ($this->fmt === 'docx' || $this->fmt === 'pdf') $this->r->pageBreak(); return $this; }
    public function kpi(array $cards): self { $this->r->kpi($cards); return $this; }

    public function table(array $header, array $rows, array $o = []): self
    {
        $this->n++;
        $o2 = ['right' => $o['right'] ?? [], 'notitle' => true];
        // tabelle sotto un titolo di livello 3 (es. un contratto nel «Dettaglio per commessa»): in XLSX un solo foglio
        // per la sezione, con il titolo come prima colonna, invece di un foglio per ciascuna
        if ($this->lvl >= 3 && $this->sect !== '') $o2['group'] = [$this->sect, $this->last];
        $this->r->table($this->last !== '' ? $this->last : 'Tabella ' . $this->n, $header, $rows, $o2);
        return $this;
    }

    public function bars(array $rows, array $o = []): self
    {
        $this->r->bars((string)($o['title'] ?? $this->last), $rows, '', (string)($o['color'] ?? '2563EB'));
        return $this;
    }

    public function stackedbars(array $rows, array $segs, array $o = []): self
    {
        $this->r->stacked((string)($o['title'] ?? ''), $rows, $segs);
        return $this;
    }

    /** Invia nel formato dell'adattatore (il nome file perde l'estensione originale). */
    public function download(string $filename): void
    {
        $this->r->send($this->fmt, preg_replace('/\.[a-z]+$/i', '', $filename));
    }
}
