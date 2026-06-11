<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail\LogAnalysis;

/**
 * Konektor pro logy MailEnable (Windows MTA), sada „SMTP-Activity".
 *
 * MailEnable píše tři druhy logů (SMTP-Activity, SMTP-Debug, W3C ex*). Záměrně
 * čteme jen SMTP-Activity — má reálné session GUID (čisté seskupení spojení),
 * předmět zprávy, peer IP a per-příkaz odpověď serveru; je nejlépe parsovatelný.
 *
 * Formát (tab-separated, jeden řádek = jeden SMTP příkaz a odpověď):
 *   MM/DD/YY HH:MM:SS \t SMTP-IN|SMTP-OU \t {guid}.MAI \t thread \t peerIP
 *     \t COMMAND \t "detail příkazu" \t "odpověď serveru" \t bytesOut \t bytesIn \t  \t Předmět
 *
 *  - SMTP-IN = příchozí podání (klient → server)        → KIND_SUBMISSION
 *  - SMTP-OU = odchozí doručení (server → cílový MX)    → KIND_DELIVERY
 *
 * Problém s doručením se pozná z odpovědi: u CONN (host odmítl naše IP —
 * rDNS/antispam), u RCPT (odmítnutý/neexistující příjemce) i po DATA (550/451…).
 */
final class MailEnableLogConnector implements SmtpLogConnectorInterface
{
    public function key(): string
    {
        return 'mailenable';
    }

    public function label(): string
    {
        return 'MailEnable';
    }

    public function matchesFile(string $path, string $firstChunk): bool
    {
        $base = strtolower(basename($path));
        // Jen Activity sada — Debug/W3C(ex*) ignorujeme.
        if (str_contains($base, 'smtp-debug')) {
            return false;
        }
        if (str_contains($base, 'smtp-activity')) {
            return true;
        }
        // Heuristika obsahu: tab-separated řádek se SMTP-IN/OU a .MAI session.
        return (bool) preg_match('/\tSMTP-(IN|OU)\t[0-9A-Fa-f]+\.MAI\t/', $firstChunk);
    }

    public function parse(string $contents, string $sourceFile): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];

        /** @var array<string,array{dir:string,ip:?string,rows:list<array{ts:string,cmd:string,detail:string,resp:string,subject:string}>}> $sessions */
        $sessions = [];

        foreach ($lines as $line) {
            if ($line === '' || str_contains($line, '******')) {
                continue;
            }
            $f = explode("\t", $line);
            if (count($f) < 8) {
                continue;
            }
            $dir = $f[1] ?? '';
            if ($dir !== 'SMTP-IN' && $dir !== 'SMTP-OU') {
                continue;
            }
            $guid = $f[2] ?? '';
            if ($guid === '') {
                continue;
            }
            $ts = $this->normalizeTs($f[0] ?? '');
            $sessions[$guid] ??= ['dir' => $dir, 'ip' => ($f[4] ?? '') !== '' ? $f[4] : null, 'rows' => []];
            $sessions[$guid]['rows'][] = [
                'ts'      => $ts,
                'cmd'     => $f[5] ?? '',
                'detail'  => $f[6] ?? '',
                'resp'    => $f[7] ?? '',
                'subject' => $f[11] ?? '',
            ];
        }

        $events = [];
        foreach ($sessions as $guid => $sess) {
            $event = $sess['dir'] === 'SMTP-IN'
                ? $this->buildSubmission($guid, $sess, $sourceFile)
                : $this->buildDelivery($guid, $sess, $sourceFile);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * @param array{dir:string,ip:?string,rows:list<array{ts:string,cmd:string,detail:string,resp:string,subject:string}>} $sess
     */
    private function buildSubmission(string $guid, array $sess, string $sourceFile): ?SmtpLogEvent
    {
        $from = null;
        $recipients = [];
        $subject = null;
        $code = null;
        $response = null;
        $ts = $sess['rows'][0]['ts'] ?? '';

        foreach ($sess['rows'] as $r) {
            $subject = $subject ?? ($r['subject'] !== '' ? $r['subject'] : null);
            $cmd = strtoupper($r['cmd']);
            if ($cmd === 'MAIL' && preg_match('/FROM:\s*<([^>]*)>/i', $r['detail'], $m)) {
                $from = $m[1] !== '' ? $m[1] : null;
            } elseif ($cmd === 'RCPT' && preg_match('/TO:\s*<([^>]*)>/i', $r['detail'], $m)) {
                if ($m[1] !== '') {
                    $recipients[] = $m[1];
                }
            } elseif ($cmd === 'DATA' || $cmd === 'DATE') {
                // Konec dat (detail '.') / akceptace těla — finální odpověď k podání.
                if (preg_match('/^(\d{3})\s*(.*)$/', $r['resp'], $m)) {
                    $code = (int) $m[1];
                    $response = $this->clean($m[2]);
                }
            }
        }

        if ($from === null && $recipients === []) {
            return null;
        }

        $status = match (true) {
            $code !== null && $code >= 500 => SmtpLogEvent::STATUS_REJECTED,
            $code !== null && $code >= 400 => SmtpLogEvent::STATUS_DEFERRED,
            $code !== null && $code >= 200 => SmtpLogEvent::STATUS_QUEUED,
            default                        => SmtpLogEvent::STATUS_QUEUED,
        };

        return new SmtpLogEvent(
            ts: $ts,
            kind: SmtpLogEvent::KIND_SUBMISSION,
            status: $status,
            mailFrom: $from,
            recipients: array_values(array_unique($recipients)),
            remoteHost: null,
            remoteIp: $sess['ip'],
            code: $code,
            response: $response,
            messageId: $guid,
            sourceFile: $sourceFile,
            session: $guid,
            subject: $subject,
        );
    }

    /**
     * @param array{dir:string,ip:?string,rows:list<array{ts:string,cmd:string,detail:string,resp:string,subject:string}>} $sess
     */
    private function buildDelivery(string $guid, array $sess, string $sourceFile): ?SmtpLogEvent
    {
        $from = null;
        $subject = null;
        $remoteHost = null;
        $ts = $sess['rows'][0]['ts'] ?? '';
        $connCode = null;
        $connResp = null;
        /** @var list<array{addr:string,code:?int,resp:?string}> $rcpts */
        $rcpts = [];
        $dataCode = null;
        $dataResp = null;

        foreach ($sess['rows'] as $r) {
            $subject = $subject ?? ($r['subject'] !== '' ? $r['subject'] : null);
            $cmd = strtoupper($r['cmd']);
            [$rcode, $rtext] = $this->splitResp($r['resp']);

            if ($cmd === 'CONN') {
                if ($remoteHost === null) {
                    $remoteHost = $this->hostFromBanner($r['resp']);
                }
                $connCode = $rcode;
                $connResp = $rtext;
            } elseif ($cmd === 'MAIL' && preg_match('/FROM:\s*<([^>]*)>/i', $r['detail'], $m)) {
                $from = $m[1] !== '' ? $m[1] : null;
            } elseif ($cmd === 'RCPT' && preg_match('/TO:\s*<([^>]*)>/i', $r['detail'], $m)) {
                if ($m[1] !== '') {
                    $rcpts[] = ['addr' => $m[1], 'code' => $rcode, 'resp' => $rtext];
                }
            } elseif (($cmd === 'DATA' || $cmd === 'DATE') && trim($r['detail']) === '.') {
                $dataCode = $rcode;
                $dataResp = $rtext;
            }
        }

        if ($rcpts === [] && $from === null && $connCode === null) {
            return null;
        }

        $rejected = array_values(array_filter($rcpts, static fn ($x) => $x['code'] !== null && $x['code'] >= 500));
        $deferred = array_values(array_filter($rcpts, static fn ($x) => $x['code'] !== null && $x['code'] >= 400 && $x['code'] < 500));
        $accepted = array_values(array_filter($rcpts, static fn ($x) => $x['code'] !== null && $x['code'] >= 200 && $x['code'] < 300));

        if ($dataCode !== null && $dataCode >= 200 && $dataCode < 300) {
            $status = SmtpLogEvent::STATUS_DELIVERED;
            $code = $dataCode;
            $response = $dataResp;
            $recipients = $accepted !== [] ? array_column($accepted, 'addr') : array_column($rcpts, 'addr');
        } elseif ($dataCode !== null && $dataCode >= 500) {
            $status = SmtpLogEvent::STATUS_REJECTED;
            $code = $dataCode;
            $response = $dataResp;
            $recipients = array_column($rcpts, 'addr');
        } elseif ($dataCode !== null && $dataCode >= 400) {
            $status = SmtpLogEvent::STATUS_DEFERRED;
            $code = $dataCode;
            $response = $dataResp;
            $recipients = array_column($rcpts, 'addr');
        } elseif ($rejected !== []) {
            $status = SmtpLogEvent::STATUS_REJECTED;
            $code = $rejected[0]['code'];
            $response = $rejected[0]['resp'];
            $recipients = array_column($rejected, 'addr');
        } elseif ($deferred !== []) {
            $status = SmtpLogEvent::STATUS_DEFERRED;
            $code = $deferred[0]['code'];
            $response = $deferred[0]['resp'];
            $recipients = array_column($deferred, 'addr');
        } elseif ($connCode !== null && $connCode >= 500) {
            // Host odmítl spojení (antispam/blokace IP) — žádný RCPT neproběhl.
            $status = SmtpLogEvent::STATUS_REJECTED;
            $code = $connCode;
            $response = $connResp;
            $recipients = [];
        } elseif ($connCode !== null && $connCode >= 400) {
            // rDNS/greylisting na úrovni spojení (450 cannot find your hostname…).
            $status = SmtpLogEvent::STATUS_DEFERRED;
            $code = $connCode;
            $response = $connResp;
            $recipients = [];
        } elseif ($accepted !== []) {
            // RCPT OK, ale chybí potvrzení po DATA — neúplná session.
            $status = SmtpLogEvent::STATUS_ERROR;
            $code = null;
            $response = null;
            $recipients = array_column($accepted, 'addr');
        } else {
            $status = SmtpLogEvent::STATUS_ERROR;
            $code = null;
            $response = null;
            $recipients = array_column($rcpts, 'addr');
        }

        return new SmtpLogEvent(
            ts: $ts,
            kind: SmtpLogEvent::KIND_DELIVERY,
            status: $status,
            mailFrom: $from,
            recipients: array_values(array_unique($recipients)),
            remoteHost: $remoteHost,
            remoteIp: $sess['ip'],
            code: $code,
            response: $response,
            messageId: $guid,
            sourceFile: $sourceFile,
            session: $guid,
            subject: $subject,
        );
    }

    /**
     * `MM/DD/YY HH:MM:SS` → `YYYY-MM-DD HH:MM:SS` (rok 20YY).
     */
    private function normalizeTs(string $raw): string
    {
        if (preg_match('#^(\d{2})/(\d{2})/(\d{2})\s+(\d{2}:\d{2}:\d{2})$#', trim($raw), $m)) {
            return sprintf('20%s-%s-%s %s', $m[3], $m[1], $m[2], $m[4]);
        }
        return trim($raw);
    }

    /**
     * @return array{0:?int,1:?string}
     */
    private function splitResp(string $resp): array
    {
        $resp = trim($resp);
        if (preg_match('/^(\d{3})[ -]?(.*)$/s', $resp, $m)) {
            return [(int) $m[1], $this->clean($m[2])];
        }
        return [null, $resp !== '' ? $this->clean($resp) : null];
    }

    private function hostFromBanner(string $resp): ?string
    {
        if (preg_match('/^\d{3}[ -]+([A-Za-z0-9][A-Za-z0-9.\-]+\.[A-Za-z]{2,})/', trim($resp), $m)) {
            return $m[1];
        }
        return null;
    }

    private function clean(string $s): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $s));
    }
}
