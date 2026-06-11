<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * DELETE /api/invoices/{id}/link-advance
 *
 * Zruší propojení daňového dokladu se zálohovou fakturou (proforma).
 * advance_paid_amount ponecháme (ruční korekce). Vrací aktualizovaný invoice payload.
 */
final class UnlinkAdvanceAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        $supplierId = SupplierGuard::currentId($request);
        $this->repo->unlinkAdvance($id, $supplierId);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.advance_unlinked', $user['id'] ?? null, 'invoice', $id, [
            'previous_advance_id' => $invoice['parent_invoice_id'] ?? null,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id));
    }
}
