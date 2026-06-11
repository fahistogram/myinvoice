<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Repository\InvoiceRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/invoices/{id}/final-candidates
 *
 * Opačný směr párování — z detailu zálohové faktury vrátí nepropojené daňové
 * doklady (invoice_type='invoice') stejného odběratele, se kterými lze zálohu spárovat.
 */
final class FinalCandidatesAction
{
    public function __construct(private readonly InvoiceRepository $repo) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        $supplierId = SupplierGuard::currentId($request);
        return Json::ok($response, ['candidates' => $this->repo->finalCandidates($id, $supplierId)]);
    }
}
