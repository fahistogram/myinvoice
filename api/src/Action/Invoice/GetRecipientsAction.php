<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Mail\RecipientResolver;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/invoices/{id}/recipients?type=documents|reminders|approvals
 *
 * Vrátí vyřešené příjemce pro modal odeslání (#86) — jediný zdroj pravdy je
 * backend (RecipientResolver); frontend dřív duplikoval skládání v JS.
 * `resolved` nese provenanci (kontakt: účel/popisek, zakázka, hlavní e-mail),
 * UI ji zobrazí jako chips a nechá uživatele seznam ručně upravit.
 */
final class GetRecipientsAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly RecipientResolver $recipients,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        $type = (string) (($request->getQueryParams()['type'] ?? '') ?: RecipientResolver::TYPE_DOCUMENTS);
        if (!in_array($type, [RecipientResolver::TYPE_DOCUMENTS, RecipientResolver::TYPE_REMINDERS, RecipientResolver::TYPE_APPROVALS], true)) {
            return Json::error($response, 'invalid_type', 'type musí být documents|reminders|approvals.', 400);
        }

        $r = $this->recipients->resolve($type, $invoice);
        return Json::ok($response, [
            'type'     => $type,
            'to'       => $r['to'],
            'cc'       => $r['cc'],
            'bcc'      => $r['bcc'],
            'resolved' => $r['resolved'],
        ]);
    }
}
