<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice\Parser;

final readonly class BankEmailNoticeProvider
{
    /**
     * @param array<string,mixed> $fieldPatterns
     * @param array<string,mixed> $normalizerConfig
     */
    public function __construct(
        public ?int $id,
        public ?int $supplierId,
        public string $providerRef,
        public string $code,
        public string $name,
        public string $parserType,
        public bool $enabled,
        public ?string $senderWhitelist,
        public ?string $subjectPattern,
        public ?string $bodyPattern,
        public array $fieldPatterns,
        public array $normalizerConfig,
        public bool $system,
    ) {}

}
