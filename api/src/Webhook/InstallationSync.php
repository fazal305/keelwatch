<?php

declare(strict_types=1);

namespace Keelwatch\Webhook;

use PDO;

/**
 * Keeps installations and repositories in step with what GitHub reports.
 * Rows are soft-deleted, never removed, so history stays traceable.
 */
final class InstallationSync
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function upsertInstallation(int $githubId, string $accountLogin, string $accountType): int
    {
        if ($accountType !== 'User' && $accountType !== 'Organization') {
            throw new NormalizationException('installation account type must be User or Organization');
        }
        $this->pdo->prepare(
            "INSERT INTO installations (github_installation_id, account_login, account_type)
             VALUES (?, ?, ?) AS new
             ON DUPLICATE KEY UPDATE
                 account_login = new.account_login,
                 account_type = new.account_type,
                 updated_at = UTC_TIMESTAMP(3),
                 id = LAST_INSERT_ID(installations.id)"
        )->execute([$githubId, $accountLogin, $accountType]);

        return (int) $this->pdo->lastInsertId();
    }

    public function setInstallationStatus(int $githubId, string $status): void
    {
        $column = match ($status) {
            'deleted' => 'deleted_at = UTC_TIMESTAMP(3)',
            'suspended' => 'suspended_at = UTC_TIMESTAMP(3)',
            'active' => 'suspended_at = NULL',
        };
        $this->pdo->prepare(
            "UPDATE installations SET status = ?, {$column}, updated_at = UTC_TIMESTAMP(3)
             WHERE github_installation_id = ?"
        )->execute([$status, $githubId]);
    }

    /**
     * New repositories start with an LLM policy from ADR 0003: private
     * repositories send nothing; public ones may use providers only while
     * they stay public. An existing policy is never overwritten here.
     */
    public function upsertRepository(
        int $installationId,
        int $githubRepoId,
        string $fullName,
        bool $isPrivate,
        ?string $defaultBranch,
    ): int {
        $policy = $isPrivate ? 'none' : 'public_only';
        $this->pdo->prepare(
            'INSERT INTO repositories (installation_id, github_repo_id, full_name, is_private, default_branch, llm_policy)
             VALUES (?, ?, ?, ?, ?, ?) AS new
             ON DUPLICATE KEY UPDATE
                 installation_id = new.installation_id,
                 full_name = new.full_name,
                 is_private = new.is_private,
                 default_branch = COALESCE(new.default_branch, repositories.default_branch),
                 removed_at = NULL,
                 updated_at = UTC_TIMESTAMP(3),
                 id = LAST_INSERT_ID(repositories.id)'
        )->execute([$installationId, $githubRepoId, $fullName, $isPrivate ? 1 : 0, $defaultBranch, $policy]);

        return (int) $this->pdo->lastInsertId();
    }

    public function markRepositoryRemoved(int $githubRepoId): void
    {
        $this->pdo->prepare(
            'UPDATE repositories SET removed_at = UTC_TIMESTAMP(3), updated_at = UTC_TIMESTAMP(3)
             WHERE github_repo_id = ? AND removed_at IS NULL'
        )->execute([$githubRepoId]);
    }

    /**
     * Applies an `installation` or `installation_repositories` delivery.
     *
     * @param array<string, mixed> $data
     */
    public function applyInstallationEvent(string $event, array $data): void
    {
        $payload = new Payload($data);
        $action = $payload->string('action', 64);
        $githubId = $payload->positiveInt('installation.id');

        if ($event === 'installation' && $action === 'deleted') {
            $this->setInstallationStatus($githubId, 'deleted');
            return;
        }
        if ($event === 'installation' && $action === 'suspend') {
            $this->setInstallationStatus($githubId, 'suspended');
            return;
        }

        $installationId = $this->upsertInstallation(
            $githubId,
            $payload->string('installation.account.login', 100),
            $payload->string('installation.account.type', 16),
        );
        if ($event === 'installation' && $action === 'unsuspend') {
            $this->setInstallationStatus($githubId, 'active');
        }

        $added = $event === 'installation' ? 'repositories' : 'repositories_added';
        foreach ($payload->list($added) as $i => $_) {
            $this->upsertRepository(
                $installationId,
                $payload->positiveInt("{$added}.{$i}.id"),
                $payload->fullName("{$added}.{$i}.full_name"),
                $payload->bool("{$added}.{$i}.private"),
                null,
            );
        }
        foreach ($payload->list('repositories_removed') as $i => $_) {
            $this->markRepositoryRemoved($payload->positiveInt("repositories_removed.{$i}.id"));
        }
    }
}
