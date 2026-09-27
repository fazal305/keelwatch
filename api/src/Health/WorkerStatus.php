<?php

declare(strict_types=1);

namespace Keelwatch\Health;

final class WorkerStatus
{
    public const RUNNING = 'running';
    public const STOPPING = 'stopping';
    public const STOPPED = 'stopped';
    public const STALE = 'stale';

    /**
     * Ages come from the database clock (UTC_TIMESTAMP), not PHP's, so
     * clock drift between the API host and the DB can't fake a stale worker.
     */
    public static function classify(string $reportedStatus, float $ageSeconds, int $staleAfterSeconds): string
    {
        if ($reportedStatus === self::STOPPED) {
            return self::STOPPED;
        }
        if ($ageSeconds > $staleAfterSeconds) {
            return self::STALE;
        }
        return $reportedStatus === self::STOPPING ? self::STOPPING : self::RUNNING;
    }
}
