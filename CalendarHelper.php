<?php
/**
 * certV 2.4 — CalendarHelper.php
 * Generazione file ICS (Outlook/Apple) e link Google Calendar.
 * Timezone: Europe/Rome (ISO 8601).
 */

class CalendarHelper
{
    const TZ = 'Europe/Rome';

    /**
     * Genera contenuto .ics per un evento.
     *
     * @param string $title     Titolo evento
     * @param string $startDate Data inizio (Y-m-d)
     * @param string $startTime Ora inizio (H:i, default 09:00)
     * @param int    $durationH Durata in ore (default 2)
     * @param string $location  Luogo
     * @param string $description Descrizione testuale
     * @param string $organizer Email organizzatore
     * @param string $uid       UID univoco evento (opzionale, auto-generato)
     * @return string Contenuto ICS completo
     */
    public static function generateICS(
        string $title,
        string $startDate,
        string $startTime = '09:00',
        int    $durationH = 2,
        string $location = '',
        string $description = '',
        string $organizer = '',
        string $uid = ''
    ): string {
        $tz = new \DateTimeZone(self::TZ);
        $start = new \DateTime("$startDate $startTime", $tz);
        $end   = (clone $start)->modify("+{$durationH} hours");

        if (!$uid) $uid = bin2hex(random_bytes(16)) . '@certv';

        $dtStart = $start->format('Ymd\THis');
        $dtEnd   = $end->format('Ymd\THis');
        $dtStamp = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Ymd\THis\Z');

        // Escape ICS text
        $esc = fn(string $s) => str_replace(["\n","\\",";",","], ["\\n","\\\\","\\;","\\,"], $s);

        $ics  = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//certV//Portale Governance//IT\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "METHOD:PUBLISH\r\n";
        // Timezone definition
        $ics .= "BEGIN:VTIMEZONE\r\n";
        $ics .= "TZID:" . self::TZ . "\r\n";
        $ics .= "BEGIN:STANDARD\r\n";
        $ics .= "DTSTART:19701025T030000\r\n";
        $ics .= "RRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=10\r\n";
        $ics .= "TZOFFSETFROM:+0200\r\n";
        $ics .= "TZOFFSETTO:+0100\r\n";
        $ics .= "TZNAME:CET\r\n";
        $ics .= "END:STANDARD\r\n";
        $ics .= "BEGIN:DAYLIGHT\r\n";
        $ics .= "DTSTART:19700329T020000\r\n";
        $ics .= "RRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=3\r\n";
        $ics .= "TZOFFSETFROM:+0100\r\n";
        $ics .= "TZOFFSETTO:+0200\r\n";
        $ics .= "TZNAME:CEST\r\n";
        $ics .= "END:DAYLIGHT\r\n";
        $ics .= "END:VTIMEZONE\r\n";
        // Event
        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= "UID:$uid\r\n";
        $ics .= "DTSTAMP:$dtStamp\r\n";
        $ics .= "DTSTART;TZID=" . self::TZ . ":$dtStart\r\n";
        $ics .= "DTEND;TZID=" . self::TZ . ":$dtEnd\r\n";
        $ics .= "SUMMARY:" . $esc($title) . "\r\n";
        if ($location) $ics .= "LOCATION:" . $esc($location) . "\r\n";
        if ($description) $ics .= "DESCRIPTION:" . $esc($description) . "\r\n";
        if ($organizer) $ics .= "ORGANIZER;CN=certV:mailto:$organizer\r\n";
        // Allarmi: -1 giorno e -1 ora
        $ics .= "BEGIN:VALARM\r\n";
        $ics .= "TRIGGER:-P1D\r\n";
        $ics .= "ACTION:DISPLAY\r\n";
        $ics .= "DESCRIPTION:Promemoria: $title domani\r\n";
        $ics .= "END:VALARM\r\n";
        $ics .= "BEGIN:VALARM\r\n";
        $ics .= "TRIGGER:-PT1H\r\n";
        $ics .= "ACTION:DISPLAY\r\n";
        $ics .= "DESCRIPTION:Promemoria: $title tra 1 ora\r\n";
        $ics .= "END:VALARM\r\n";
        $ics .= "END:VEVENT\r\n";
        $ics .= "END:VCALENDAR\r\n";

        return $ics;
    }

    /**
     * Genera URL per aggiungere evento a Google Calendar.
     */
    public static function googleCalendarUrl(
        string $title,
        string $startDate,
        string $startTime = '09:00',
        int    $durationH = 2,
        string $location = '',
        string $description = ''
    ): string {
        $tz = new \DateTimeZone(self::TZ);
        $start = new \DateTime("$startDate $startTime", $tz);
        $end   = (clone $start)->modify("+{$durationH} hours");

        // Google Calendar usa UTC format
        $start->setTimezone(new \DateTimeZone('UTC'));
        $end->setTimezone(new \DateTimeZone('UTC'));

        $params = [
            'action'   => 'TEMPLATE',
            'text'     => $title,
            'dates'    => $start->format('Ymd\THis\Z') . '/' . $end->format('Ymd\THis\Z'),
            'details'  => $description,
            'location' => $location,
            'ctz'      => self::TZ,
        ];

        return 'https://calendar.google.com/calendar/render?' . http_build_query($params);
    }

    /**
     * Salva file ICS su disco e restituisce il path.
     */
    public static function saveICS(string $icsContent, string $filename): string
    {
        $dir = __DIR__ . '/uploads/ics/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $path = $dir . $filename;
        file_put_contents($path, $icsContent);
        return $path;
    }

    /**
     * Costruisce il corpo HTML email con link calendario e riepilogo.
     */
    public static function buildNotificationHtml(
        string $employeeName,
        string $eventTitle,
        string $eventType,
        string $eventDate,
        string $brandName = '',
        string $certName = '',
        string $location = '',
        string $notes = '',
        string $googleUrl = '',
        string $portalUrl = ''
    ): string {
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto">';
        $html .= '<div style="background:#1e40af;color:#fff;padding:20px 24px;border-radius:12px 12px 0 0">';
        $html .= '<h2 style="margin:0;font-size:18px">📅 ' . htmlspecialchars($eventTitle) . '</h2>';
        $html .= '</div>';

        $html .= '<div style="background:#fff;padding:24px;border:1px solid #e2e8f0">';
        $html .= '<p>Gentile <strong>' . htmlspecialchars($employeeName) . '</strong>,</p>';
        $html .= '<p>è stato pianificato un nuovo impegno nel tuo percorso formativo:</p>';

        // Riepilogo in tabella
        $html .= '<table style="width:100%;border-collapse:collapse;margin:16px 0">';
        $rows = [
            ['Tipologia', $eventType],
            ['Data', date('d/m/Y', strtotime($eventDate))],
        ];
        if ($brandName) $rows[] = ['Brand', $brandName];
        if ($certName) $rows[] = ['Certificazione', $certName];
        if ($location) $rows[] = ['Sede / Luogo', $location];
        if ($notes) $rows[] = ['Note', $notes];

        foreach ($rows as [$label, $value]) {
            $html .= '<tr>';
            $html .= '<td style="padding:8px 12px;font-weight:700;color:#475569;background:#f8fafc;border:1px solid #e2e8f0;width:140px">' . htmlspecialchars($label) . '</td>';
            $html .= '<td style="padding:8px 12px;border:1px solid #e2e8f0">' . htmlspecialchars($value) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';

        // Bottoni calendario
        $html .= '<div style="margin:20px 0;text-align:center">';
        if ($googleUrl) {
            $html .= '<a href="' . htmlspecialchars($googleUrl) . '" target="_blank" style="display:inline-block;background:#4285f4;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:700;font-size:13px;margin:4px">📅 Aggiungi a Google Calendar</a> ';
        }
        $html .= '<span style="display:inline-block;background:#0078d4;color:#fff;padding:10px 20px;border-radius:6px;font-size:13px;font-weight:700;margin:4px">📎 File .ics allegato per Outlook/Apple</span>';
        $html .= '</div>';

        if ($portalUrl) {
            $html .= '<p style="margin-top:16px"><a href="' . htmlspecialchars($portalUrl) . '" style="color:#1e40af;font-weight:600">Apri nel portale →</a></p>';
        }

        $html .= '</div>';
        $html .= '<div style="background:#f8fafc;padding:14px 24px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 12px 12px;font-size:11px;color:#94a3b8;text-align:center">';
        $html .= 'Questa email è stata generata automaticamente dal portale certV. Non rispondere a questo messaggio.';
        $html .= '</div></div>';

        return $html;
    }
}
