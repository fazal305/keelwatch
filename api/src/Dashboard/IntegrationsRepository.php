<?php

declare(strict_types=1);

namespace Keelwatch\Dashboard;

use PDO;

/**
 * Notification destinations and per-repository settings.
 *
 * A destination's URL is a credential, so it is write-only here: it is stored
 * encrypted and no method returns it (or its ciphertext). Reads expose only
 * the host, which is what the SSRF allowlist is audited against.
 */
final class IntegrationsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function installations(): array
    {
        $rows = $this->pdo->query(
            "SELECT id, github_installation_id, account_login, account_type, status
               FROM installations ORDER BY status = 'active' DESC, account_login"
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'github_installation_id' => (int) $r['github_installation_id'],
            'account_login' => $r['account_login'],
            'account_type' => $r['account_type'],
            'status' => $r['status'],
        ], $rows);
    }

    public function installationIsActive(int $id): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM installations WHERE id = ? AND status = 'active'");
        $stmt->execute([$id]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function destinations(): array
    {
        $stmt = $this->pdo->query(self::DESTINATION_SELECT . ' ORDER BY d.id');
        return array_map(self::destinationRow(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function destination(int $id): ?array
    {
        $stmt = $this->pdo->prepare(self::DESTINATION_SELECT . ' WHERE d.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::destinationRow($row);
    }

    public function createDestination(int $installationId, string $kind, string $label, string $ciphertext, string $host, string $minSeverity, bool $enabled): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO notification_destinations
                (installation_id, kind, label, url_ciphertext, url_host, min_severity, enabled)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bindValue(1, $installationId, PDO::PARAM_INT);
        $stmt->bindValue(2, $kind);
        $stmt->bindValue(3, $label);
        $stmt->bindValue(4, $ciphertext, PDO::PARAM_LOB);
        $stmt->bindValue(5, $host);
        $stmt->bindValue(6, $minSeverity);
        $stmt->bindValue(7, $enabled ? 1 : 0, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array{label?: string, min_severity?: string, enabled?: bool, url_ciphertext?: string, url_host?: string} $changes
     */
    public function updateDestination(int $id, array $changes): bool
    {
        if ($changes === []) {
            return $this->destination($id) !== null;
        }
        // Column names come from the route's allowlist, never from the request.
        $stmt = $this->pdo->prepare(
            'UPDATE notification_destinations SET '
            . implode(', ', array_map(static fn (string $c): string => "{$c} = ?", array_keys($changes)))
            . ', updated_at = UTC_TIMESTAMP(3) WHERE id = ?'
        );
        $i = 0;
        foreach ($changes as $column => $value) {
            $stmt->bindValue(++$i, is_bool($value) ? (int) $value : $value, match (true) {
                $column === 'url_ciphertext' => PDO::PARAM_LOB,
                is_bool($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            });
        }
        $stmt->bindValue(++$i, $id, PDO::PARAM_INT);
        $stmt->execute();
        // rowCount is 0 for "matched but unchanged" too, so confirm existence separately.
        return $stmt->rowCount() > 0 || $this->destination($id) !== null;
    }

    public function deleteDestination(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM notification_destinations WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() === 1;
    }

    /**
     * @param array{llm_policy?: string, analysis_enabled?: bool} $changes
     */
    public function updateRepositorySettings(int $id, array $changes): bool
    {
        $exists = $this->pdo->prepare('SELECT 1 FROM repositories WHERE id = ?');
        $exists->execute([$id]);
        if ($exists->fetchColumn() === false) {
            return false;
        }
        if ($changes === []) {
            return true;
        }
        $sets = [];
        $values = [];
        foreach ($changes as $column => $value) {
            $sets[] = "{$column} = ?";
            $values[] = is_bool($value) ? (int) $value : $value;
        }
        $values[] = $id;
        $this->pdo->prepare('UPDATE repositories SET ' . implode(', ', $sets) . ', updated_at = UTC_TIMESTAMP(3) WHERE id = ?')
            ->execute($values);
        return true;
    }

    private const DESTINATION_SELECT = "
        SELECT d.id, d.installation_id, i.account_login, d.kind, d.label, d.url_host,
               d.min_severity, d.enabled, d.created_at, d.updated_at,
               last.status AS last_status, last.http_status AS last_http_status,
               last.error AS last_error, last.attempted_at AS last_attempted_at,
               (SELECT COUNT(*) FROM notification_deliveries nd
                 WHERE nd.destination_id = d.id AND nd.status = 'sent') AS sent_count
          FROM notification_destinations d
          JOIN installations i ON i.id = d.installation_id
          LEFT JOIN notification_deliveries last ON last.id = (
                SELECT nd.id FROM notification_deliveries nd
                 WHERE nd.destination_id = d.id
                 ORDER BY nd.attempted_at DESC, nd.id DESC LIMIT 1)";

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private static function destinationRow(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'installation' => ['id' => (int) $r['installation_id'], 'account_login' => $r['account_login']],
            'kind' => $r['kind'],
            'label' => $r['label'],
            'url_host' => $r['url_host'],
            'min_severity' => $r['min_severity'],
            'enabled' => (bool) $r['enabled'],
            'created_at' => DashboardRepository::iso($r['created_at']),
            'updated_at' => DashboardRepository::iso($r['updated_at']),
            'sent_count' => (int) $r['sent_count'],
            'last_delivery' => $r['last_status'] === null ? null : [
                'status' => $r['last_status'],
                'http_status' => $r['last_http_status'] !== null ? (int) $r['last_http_status'] : null,
                'error' => $r['last_error'],
                'attempted_at' => DashboardRepository::iso($r['last_attempted_at']),
            ],
        ];
    }
}
