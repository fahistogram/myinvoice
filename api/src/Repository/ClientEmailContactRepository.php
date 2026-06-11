<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Mail\RecipientResolver;
use PDO;

/**
 * E-mailové kontakty odběratele dle účelu (#86) — CRUD nad client_email_contacts.
 *
 * Model: kontakty se ukládají replace-all per klient (stejný vzor jako
 * project_billing_emails na zakázce) — API klienta posílá kompletní pole
 * `email_contacts`. Účely v JSON sloupci `usages`:
 *   [{"usage":"documents","recipient":"to"}, …]
 *
 * Supplier scope: caller (ClientsAction) ověřuje vlastnictví klienta;
 * repo navíc guarduje přes JOIN clients v listForClient/replaceForClient.
 */
final class ClientEmailContactRepository
{
    public const MAX_CONTACTS = 10;

    public function __construct(private readonly Connection $db) {}

    /**
     * @return list<array{id:int, email:string, label:?string, contact_name:?string,
     *                    is_active:bool, sort_order:int, usages:list<array{usage:string, recipient:string}>}>
     */
    public function listForClient(int $clientId, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT cec.* FROM client_email_contacts cec
               JOIN clients c ON c.id = cec.client_id
              WHERE cec.client_id = ? AND c.supplier_id = ?
              ORDER BY cec.sort_order, cec.id'
        );
        $stmt->execute([$clientId, $supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $usages = json_decode((string) $row['usages'], true);
            $out[] = [
                'id'           => (int) $row['id'],
                'email'        => (string) $row['email'],
                'label'        => $row['label'] !== null && $row['label'] !== '' ? (string) $row['label'] : null,
                'contact_name' => $row['contact_name'] !== null && $row['contact_name'] !== '' ? (string) $row['contact_name'] : null,
                'is_active'    => (bool) $row['is_active'],
                'sort_order'   => (int) $row['sort_order'],
                'usages'       => is_array($usages) ? $usages : [],
            ];
        }
        return $out;
    }

    /**
     * Replace-all kontaktů klienta. Vstup se validuje (e-mail, účely, limit);
     * při chybě hází \DomainException s česky čitelnou hláškou (Action ji
     * promítne do 422). Vrací normalizovaný seznam (jako listForClient).
     *
     * @param list<array<string,mixed>> $contacts
     * @return list<array<string,mixed>>
     */
    public function replaceForClient(int $clientId, int $supplierId, array $contacts): array
    {
        $normalized = $this->normalize($contacts);

        $pdo = $this->db->pdo();
        // Scope guard — klient musí patřit dodavateli.
        $own = $pdo->prepare('SELECT 1 FROM clients WHERE id = ? AND supplier_id = ?');
        $own->execute([$clientId, $supplierId]);
        if ($own->fetchColumn() === false) {
            throw new \DomainException('Klient nenalezen.');
        }

        $pdo->prepare('DELETE FROM client_email_contacts WHERE client_id = ?')->execute([$clientId]);
        if ($normalized !== []) {
            $ins = $pdo->prepare(
                'INSERT INTO client_email_contacts
                    (client_id, email, label, contact_name, is_active, sort_order, usages)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($normalized as $i => $c) {
                $ins->execute([
                    $clientId,
                    $c['email'],
                    $c['label'],
                    $c['contact_name'],
                    $c['is_active'] ? 1 : 0,
                    ($i + 1) * 10, // sort_order z pořadí v payloadu — stabilní, přeuspořádatelné
                    json_encode($c['usages'], JSON_UNESCAPED_UNICODE),
                ]);
            }
        }
        return $this->listForClient($clientId, $supplierId);
    }

    /**
     * Validace + normalizace payloadu kontaktů.
     *
     * @param list<array<string,mixed>> $contacts
     * @return list<array{email:string, label:?string, contact_name:?string,
     *                    is_active:bool, usages:list<array{usage:string, recipient:string}>}>
     */
    private function normalize(array $contacts): array
    {
        if (count($contacts) > self::MAX_CONTACTS) {
            throw new \DomainException('Maximálně ' . self::MAX_CONTACTS . ' e-mailových kontaktů na klienta.');
        }
        $out = [];
        foreach ($contacts as $c) {
            if (!is_array($c)) {
                throw new \DomainException('Neplatný formát kontaktu.');
            }
            $email = trim((string) ($c['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
                throw new \DomainException("Neplatný e-mail kontaktu: {$email}");
            }
            $usages = [];
            $seenUsages = [];
            foreach ((array) ($c['usages'] ?? []) as $u) {
                $usage = is_array($u) ? trim((string) ($u['usage'] ?? '')) : trim((string) $u);
                $recipient = is_array($u) ? trim((string) ($u['recipient'] ?? 'to')) : 'to';
                if (!in_array($usage, RecipientResolver::USAGES, true)) {
                    throw new \DomainException("Neplatný účel použití: {$usage}");
                }
                if (!in_array($recipient, RecipientResolver::RECIPIENT_KINDS, true)) {
                    throw new \DomainException("Neplatný typ příjemce: {$recipient}");
                }
                if (isset($seenUsages[$usage])) {
                    continue; // duplicitní účel — první vyhrává
                }
                $seenUsages[$usage] = true;
                $usages[] = ['usage' => $usage, 'recipient' => $recipient];
            }
            $label = trim((string) ($c['label'] ?? ''));
            $name  = trim((string) ($c['contact_name'] ?? ''));
            $out[] = [
                'email'        => $email,
                'label'        => $label !== '' ? mb_substr($label, 0, 80) : null,
                'contact_name' => $name !== '' ? mb_substr($name, 0, 120) : null,
                'is_active'    => !array_key_exists('is_active', $c) || (bool) $c['is_active'],
                'usages'       => $usages,
            ];
        }
        return $out;
    }
}
