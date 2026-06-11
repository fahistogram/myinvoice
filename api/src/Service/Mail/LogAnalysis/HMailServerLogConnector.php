<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail\LogAnalysis;

/**
 * Konektor pro logy hMailServer (Windows MTA).
 *
 * Formát řádku (tab-separated, pole v uvozovkách):
 *   "SMTPD" \t thread \t session \t "ts" \t "peerIP" \t "RECEIVED|SENT: …"
 *   "SMTPC" \t thread \t session \t "ts" \t "peerIP" \t "RECEIVED|SENT: …"
 *   "APPLICATION" \t thread \t "ts" \t "zpráva"          (bez session/IP)
 * Víceřádkové SMTP odpovědi jsou v jednom poli spojené tokenem `[nl]`.
 *
 *  - SMTPD = příchozí (klient → server): podání zprávy → {@see SmtpLogEvent::KIND_SUBMISSION}.
 *  - SMTPC = odchozí (server → cílový MX): doručovací pokus → KIND_DELIVERY.
 *  - APPLICATION „SMTPDeliverer - Message N: …" = životní cyklus zprávy
 *    (Delivering …, Relaying to host …, could not be delivered …) → korelace
 *    přes interní Message ID + KIND_NOTICE pro odložení/selhání.
 *
 * Korelace: submission/delivery události dostanou `messageId` napárováním na
 * „Delivering message N" záznam (shoda příjemce + časová blízkost). Díky tomu
 * UI ukáže celý osud zprávy: co bylo podáno → kam doručeno → kde problém.
 */
final class HMailServerLogConnector implements SmtpLogConnectorInterface
{
    public function key(): string
    {
        return 'hmailserver';
    }

    public function label(): string
    {
        return 'hMailServer';
    }

    public function matchesFile(string $path, string $firstChunk): bool
    {
        $base = strtolower(basename($path));
        if (str_contains($base, 'hmailserver')) {
            return true;
        }
        // Heuristika podle obsahu — charakteristické zdrojové tagy hMailServeru.
        return (bool) preg_match('/^"(SMTPD|SMTPC|APPLICATION|TCPIP)"\t/m', $firstChunk);
    }

    public function parse(string $contents, string $sourceFile): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];

        /** @var array<string,array<int,array{ts:string,ip:?string,msg:string}>> $sessions */
        $sessions = [];
        /** @var list<array{ts:string,id:string,from:?string,recipients:list<string>}> $deliveries */
        $deliveries = [];
        /** @var list<array{ts:string,id:string,detail:string,status:string}> $messageNotices */
        $messageNotices = [];
        /** @var array<string,string> $relayHosts  messageId => host */
        $relayHosts = [];

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $fields = array_map(
                static fn (string $f): string => trim($f, '"'),
                explode("\t", $line)
            );
            $source = $fields[0] ?? '';

            if ($source === 'SMTPD' || $source === 'SMTPC') {
                // [source, thread, session, ts, ip, msg]
                if (count($fields) < 6) {
                    continue;
                }
                $key = $source . '#' . ($fields[2] ?? '');
                $sessions[$key][] = [
                    'ts'  => $fields[3] ?? '',
                    'ip'  => ($fields[4] ?? '') !== '' ? $fields[4] : null,
                    'msg' => $fields[5] ?? '',
                ];
                continue;
            }

            if ($source === 'APPLICATION') {
                // [source, thread, ts, msg]
                $ts  = $fields[2] ?? '';
                $msg = $fields[3] ?? '';
                $this->parseApplication($ts, $msg, $deliveries, $messageNotices, $relayHosts);
            }
        }

        $events = [];

        foreach ($sessions as $key => $records) {
            $isInbound = str_starts_with($key, 'SMTPD#');
            $session = substr($key, strpos($key, '#') + 1);
            // Restart serveru může session ID recyklovat → rozdělíme na transakce
            // podle banneru (220) / MAIL FROM, ať se nepomíchají dvě spojení.
            foreach ($this->splitTransactions($records) as $txn) {
                $event = $isInbound
                    ? $this->buildSubmission($txn, $session, $sourceFile)
                    : $this->buildDelivery($txn, $session, $sourceFile);
                if ($event !== null) {
                    $events[] = $event;
                }
            }
        }

        // Notice události (odložení/selhání zprávy) + relay host info.
        foreach ($messageNotices as $n) {
            $events[] = new SmtpLogEvent(
                ts: $n['ts'],
                kind: SmtpLogEvent::KIND_NOTICE,
                status: $n['status'],
                mailFrom: $this->fromForMessageId($n['id'], $deliveries),
                recipients: $this->recipientsForMessageId($n['id'], $deliveries),
                remoteHost: $relayHosts[$n['id']] ?? null,
                remoteIp: null,
                code: null,
                response: $n['detail'],
                messageId: $n['id'],
                sourceFile: $sourceFile,
                session: '',
            );
        }

        // Korelace messageId na submission/delivery události (shoda příjemce + čas).
        $events = $this->correlate($events, $deliveries);

        return $events;
    }

    /**
     * Rozdělí záznamy jedné session na samostatné SMTP transakce — pojistka
     * proti recyklaci session ID po restartu serveru. Nové spojení pozná podle
     * uvítacího banneru „220", ale dělí JEN když aktuální transakce už viděla
     * MAIL FROM. Tím se nesplitne mid-session 220 ze STARTTLS
     * („220 2.0.0 Ready to start TLS"), které předchází MAIL FROM.
     *
     * @param array<int,array{ts:string,ip:?string,msg:string}> $records
     * @return list<array<int,array{ts:string,ip:?string,msg:string}>>
     */
    private function splitTransactions(array $records): array
    {
        $txns = [];
        $current = [];
        $seenMailFrom = false;
        foreach ($records as $r) {
            $isBanner = preg_match('/^(RECEIVED|SENT): 220[ -]/', $r['msg']) === 1;
            if ($isBanner && $seenMailFrom && $current !== []) {
                $txns[] = $current;
                $current = [];
                $seenMailFrom = false;
            }
            if (preg_match('/^(RECEIVED|SENT): MAIL FROM:/i', $r['msg']) === 1) {
                $seenMailFrom = true;
            }
            $current[] = $r;
        }
        if ($current !== []) {
            $txns[] = $current;
        }
        return $txns;
    }

    /**
     * Příchozí podání (SMTPD): obálka tak, jak ji klient/aplikace předala.
     *
     * @param array<int,array{ts:string,ip:?string,msg:string}> $txn
     */
    private function buildSubmission(array $txn, string $session, string $sourceFile): ?SmtpLogEvent
    {
        $from = null;
        $recipients = [];
        $ip = null;
        $ts = $txn[0]['ts'] ?? '';
        $code = null;
        $response = null;

        foreach ($txn as $r) {
            $ip ??= $r['ip'];
            $msg = $r['msg'];
            if (preg_match('/^RECEIVED: MAIL FROM:\s*<([^>]*)>/i', $msg, $m)) {
                $from = $m[1] !== '' ? $m[1] : null;
            } elseif (preg_match('/^RECEIVED: RCPT TO:\s*<([^>]*)>/i', $msg, $m)) {
                if ($m[1] !== '') {
                    $recipients[] = $m[1];
                }
            } elseif (preg_match('/^SENT: (\d{3}) (.+)$/', $msg, $m)) {
                // Poslední 250 (po DATA) = „Queued"; držíme nejvyšší/poslední smysluplný kód.
                $c = (int) $m[1];
                if (in_array($c, [220, 354], true)) {
                    continue; // banner / data-prompt nezajímá
                }
                $code = $c;
                $response = $this->collapse($m[2]);
            }
        }

        if ($from === null && $recipients === []) {
            return null; // nejspíš jen QUIT/spojení bez transakce
        }

        $status = match (true) {
            $code !== null && $code >= 500 => SmtpLogEvent::STATUS_REJECTED,
            $code !== null && $code >= 400 => SmtpLogEvent::STATUS_DEFERRED,
            $code !== null && $code >= 200 => SmtpLogEvent::STATUS_QUEUED,
            default                        => SmtpLogEvent::STATUS_ERROR,
        };

        return new SmtpLogEvent(
            ts: $ts,
            kind: SmtpLogEvent::KIND_SUBMISSION,
            status: $status,
            mailFrom: $from,
            recipients: array_values(array_unique($recipients)),
            remoteHost: null,
            remoteIp: $ip,
            code: $code,
            response: $response,
            messageId: null,
            sourceFile: $sourceFile,
            session: $session,
        );
    }

    /**
     * Odchozí doručovací pokus (SMTPC) na cílový MX.
     *
     * @param array<int,array{ts:string,ip:?string,msg:string}> $txn
     */
    private function buildDelivery(array $txn, string $session, string $sourceFile): ?SmtpLogEvent
    {
        $from = null;
        $ip = null;
        $ts = $txn[0]['ts'] ?? '';
        $remoteHost = null;
        /** @var list<array{addr:string,code:?int,resp:?string}> $rcpts */
        $rcpts = [];
        $pendingRcpt = null;     // adresa čekající na odpověď
        $afterData = false;      // jsme za „SENT: ." (konec DATA)?
        $dataCode = null;
        $dataResp = null;

        foreach ($txn as $r) {
            $ip ??= $r['ip'];
            $msg = $r['msg'];

            if ($remoteHost === null && preg_match('/^RECEIVED: 220[ -]+([A-Za-z0-9.\-]+)/', $msg, $m)) {
                $remoteHost = $m[1];
            } elseif (preg_match('/^SENT: MAIL FROM:\s*<([^>]*)>/i', $msg, $m)) {
                $from = $m[1] !== '' ? $m[1] : null;
            } elseif (preg_match('/^SENT: RCPT TO:\s*<([^>]*)>/i', $msg, $m)) {
                $pendingRcpt = $m[1];
                $rcpts[] = ['addr' => $m[1], 'code' => null, 'resp' => null];
            } elseif (preg_match('/^SENT: \[nl\]\.\s*$/', $msg) || preg_match('/^SENT: \.\s*$/', $msg)) {
                $afterData = true;
            } elseif (preg_match('/^RECEIVED: (\d{3})[ -]?(.*)$/', $msg, $m)) {
                $c = (int) $m[1];
                if (in_array($c, [220, 221, 354], true)) {
                    continue; // banner / bye / data-prompt
                }
                if ($afterData) {
                    $dataCode = $c;
                    $dataResp = $this->collapse($m[2]);
                } elseif ($pendingRcpt !== null && $rcpts !== []) {
                    $last = array_key_last($rcpts);
                    $rcpts[$last]['code'] = $c;
                    $rcpts[$last]['resp'] = $this->collapse($m[2]);
                    $pendingRcpt = null;
                }
            }
        }

        if ($rcpts === [] && $from === null) {
            return null;
        }

        // Souhrnný stav transakce.
        $rejected = array_values(array_filter($rcpts, static fn ($x) => $x['code'] !== null && $x['code'] >= 500));
        $deferred = array_values(array_filter($rcpts, static fn ($x) => $x['code'] !== null && $x['code'] >= 400 && $x['code'] < 500));
        $accepted = array_values(array_filter($rcpts, static fn ($x) => $x['code'] !== null && $x['code'] >= 200 && $x['code'] < 300));

        if ($dataCode !== null && $dataCode >= 200 && $dataCode < 300) {
            $status = SmtpLogEvent::STATUS_DELIVERED;
            $code = $dataCode;
            $response = $dataResp;
            $recipients = $accepted !== [] ? array_column($accepted, 'addr') : array_column($rcpts, 'addr');
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
            remoteIp: $ip,
            code: $code,
            response: $response,
            messageId: null,
            sourceFile: $sourceFile,
            session: $session,
        );
    }

    /**
     * Rozparsuje APPLICATION řádek SMTPDelivereru.
     *
     * @param list<array{ts:string,id:string,from:?string,recipients:list<string>}> $deliveries
     * @param list<array{ts:string,id:string,detail:string,status:string}> $messageNotices
     * @param array<string,string> $relayHosts
     */
    private function parseApplication(
        string $ts,
        string $msg,
        array &$deliveries,
        array &$messageNotices,
        array &$relayHosts,
    ): void {
        if (!preg_match('/^SMTPDeliverer - Message (\d+):\s*(.+)$/s', $msg, $m)) {
            return;
        }
        $id = $m[1];
        $body = trim($m[2]);

        if (preg_match('/^Delivering message from\s+(.+?)\s+to\s+(.+?)\.\s*(?:File:.*)?$/s', $body, $mm)) {
            $from = trim($mm[1]);
            $recipients = array_values(array_filter(array_map('trim', explode(',', $mm[2])), static fn ($x) => $x !== ''));
            $deliveries[] = [
                'ts'         => $ts,
                'id'         => $id,
                'from'       => $from !== '' ? $from : null,
                'recipients' => $recipients,
            ];
            return;
        }

        if (preg_match('/Relaying to host\s+([A-Za-z0-9.\-]+)/', $body, $mm)) {
            $relayHosts[$id] = $mm[1];
            return;
        }

        if (stripos($body, 'could not be delivered') !== false) {
            $messageNotices[] = [
                'ts'     => $ts,
                'id'     => $id,
                'detail' => $body,
                'status' => SmtpLogEvent::STATUS_DEFERRED,
            ];
            return;
        }

        if (preg_match('/(fatal|permanent|bounce|returned to sender|giving up)/i', $body)) {
            $messageNotices[] = [
                'ts'     => $ts,
                'id'     => $id,
                'detail' => $body,
                'status' => SmtpLogEvent::STATUS_REJECTED,
            ];
        }
    }

    /**
     * Doplní messageId na submission/delivery události napárováním na
     * „Delivering message N" záznamy (shoda příjemce + nejbližší čas).
     *
     * @param list<SmtpLogEvent> $events
     * @param list<array{ts:string,id:string,from:?string,recipients:list<string>}> $deliveries
     * @return list<SmtpLogEvent>
     */
    private function correlate(array $events, array $deliveries): array
    {
        if ($deliveries === []) {
            return $events;
        }
        $out = [];
        foreach ($events as $e) {
            if ($e->messageId !== null || $e->kind === SmtpLogEvent::KIND_NOTICE || $e->recipients === []) {
                $out[] = $e;
                continue;
            }
            $best = null;
            $bestDelta = null;
            foreach ($deliveries as $d) {
                if (array_intersect($e->recipients, $d['recipients']) === []) {
                    continue;
                }
                // submission předchází Delivering; delivery následuje po něm — bereme nejbližší.
                $delta = abs(strtotime($e->ts) - strtotime($d['ts']));
                if ($bestDelta === null || $delta < $bestDelta) {
                    $bestDelta = $delta;
                    $best = $d;
                }
            }
            if ($best !== null) {
                $e = new SmtpLogEvent(
                    ts: $e->ts,
                    kind: $e->kind,
                    status: $e->status,
                    mailFrom: $e->mailFrom ?? $best['from'],
                    recipients: $e->recipients,
                    remoteHost: $e->remoteHost,
                    remoteIp: $e->remoteIp,
                    code: $e->code,
                    response: $e->response,
                    messageId: $best['id'],
                    sourceFile: $e->sourceFile,
                    session: $e->session,
                );
            }
            $out[] = $e;
        }
        return $out;
    }

    /**
     * @param list<array{ts:string,id:string,from:?string,recipients:list<string>}> $deliveries
     * @return list<string>
     */
    private function recipientsForMessageId(string $id, array $deliveries): array
    {
        foreach ($deliveries as $d) {
            if ($d['id'] === $id) {
                return $d['recipients'];
            }
        }
        return [];
    }

    /**
     * @param list<array{ts:string,id:string,from:?string,recipients:list<string>}> $deliveries
     */
    private function fromForMessageId(string $id, array $deliveries): ?string
    {
        foreach ($deliveries as $d) {
            if ($d['id'] === $id) {
                return $d['from'];
            }
        }
        return null;
    }

    private function collapse(string $s): string
    {
        $s = str_replace('[nl]', ' ', $s);
        return trim((string) preg_replace('/\s+/', ' ', $s));
    }
}
