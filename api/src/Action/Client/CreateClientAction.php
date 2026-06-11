<?php

declare(strict_types=1);

namespace MyInvoice\Action\Client;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ClientEmailContactRepository;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Validation;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CreateClientAction
{
    public function __construct(
        private readonly ClientRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly ClientEmailContactRepository $emailContacts,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $errors = Validation::client($body);
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Nelze vytvořit klienta — chybí supplier kontext.', 400);
        }
        try {
            $id = $this->repo->create($body, $supplierId);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'integrity_violation', $e->getMessage(), 400);
        }

        // E-mailové kontakty dle účelu (#86) — replace-all, validuje repo.
        if (isset($body['email_contacts']) && is_array($body['email_contacts'])) {
            try {
                $this->emailContacts->replaceForClient($id, $supplierId, $body['email_contacts']);
            } catch (\DomainException $e) {
                return Json::error($response, 'invalid_email_contacts', $e->getMessage(), 422);
            }
        }
        $client = $this->repo->find($id);
        $client['email_contacts'] = $this->emailContacts->listForClient($id, $supplierId);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('client.created', $user['id'] ?? null, 'client', $id, [
            'company_name' => $body['company_name'],
            'ic' => $body['ic'] ?? null,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $client, 201);
    }
}
