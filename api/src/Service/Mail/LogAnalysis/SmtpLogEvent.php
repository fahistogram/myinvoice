<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail\LogAnalysis;

/**
 * Jedna jednotná událost z logu poštovního serveru — výstup konektoru,
 * nezávislý na konkrétním formátu/serveru. Analyzer i UI pracují jen s tímto.
 *
 * Tři druhy ({@see KIND_*}):
 *  - submission: zpráva vstoupila na server (klient → MTA; typicky aplikace).
 *                `recipients` = obálka tak, jak byla podána (tady se pozná
 *                např. chybějící BCC příjemce).
 *  - delivery:   pokus o doručení na vzdálený server (MTA → cílový MX).
 *                `recipients` = příjemci v tomto RCPT TO; `status` = výsledek.
 *  - notice:     informativní/chybová událost vázaná na zprávu (odložené
 *                doručení, trvalé selhání, relay na smart host…).
 */
final class SmtpLogEvent
{
    public const KIND_SUBMISSION = 'submission';
    public const KIND_DELIVERY   = 'delivery';
    public const KIND_NOTICE     = 'notice';

    // Jednotné stavy napříč servery.
    public const STATUS_DELIVERED = 'delivered'; // 2xx po DATA — přijato cílovým serverem
    public const STATUS_QUEUED    = 'queued';    // přijato k doručení (submission), zatím neodesláno dál
    public const STATUS_DEFERRED  = 'deferred';  // 4xx — dočasné selhání, naplánován retry
    public const STATUS_REJECTED  = 'rejected';  // 5xx — trvalé odmítnutí
    public const STATUS_ERROR     = 'error';     // chyba spojení / neúplný dialog
    public const STATUS_INFO      = 'info';      // jen informace (notice)

    /**
     * @param self::KIND_*   $kind
     * @param self::STATUS_* $status
     * @param list<string>   $recipients
     */
    public function __construct(
        public readonly string $ts,            // 'YYYY-MM-DD HH:MM:SS.mmm' (lokální čas serveru)
        public readonly string $kind,
        public readonly string $status,
        public readonly ?string $mailFrom,
        public readonly array $recipients,
        public readonly ?string $remoteHost,   // cílový/zdrojový hostname (z banneru), je-li znám
        public readonly ?string $remoteIp,
        public readonly ?int $code,            // poslední SMTP kód (250, 450, 550…)
        public readonly ?string $response,     // text poslední odpovědi serveru
        public readonly ?string $messageId,    // interní ID zprávy serveru (koreluje submission↔delivery)
        public readonly string $sourceFile,
        public readonly string $session,       // ID session v rámci souboru (debug/korelace)
        public readonly ?string $subject = null, // předmět zprávy, pokud ho log nese (MailEnable ano, hMailServer ne)
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'ts'          => $this->ts,
            'kind'        => $this->kind,
            'status'      => $this->status,
            'mail_from'   => $this->mailFrom,
            'recipients'  => $this->recipients,
            'remote_host' => $this->remoteHost,
            'remote_ip'   => $this->remoteIp,
            'code'        => $this->code,
            'response'    => $this->response,
            'message_id'  => $this->messageId,
            'source_file' => $this->sourceFile,
            'session'     => $this->session,
            'subject'     => $this->subject,
        ];
    }
}
