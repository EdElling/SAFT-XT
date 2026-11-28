<?php

declare(strict_types=1);

namespace App\Tasks\Types;

use App\Exceptions\Database\DatabaseErrorClassifier;
use App\Exceptions\Database\DatabaseErrorType;
use App\Tasks\TaskResult;
use App\Tasks\TaskSkipped;
use App\Tasks\TaskType;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Session\FileSessionHandler;
use Illuminate\Session\SessionManager;
use Throwable;

/**
 * System-Aufgabe „Sitzungen aufräumen“ (B-177, Architekturentscheidung Nr. 32), stündlich: löscht abgelaufene Sitzungen
 * nach SESSION_LIFETIME. Ersetzt Laravels Lotterie (config/session.php, ausgeschaltet), deren
 * `DELETE … WHERE last_activity <= ?` auf MySQL/MariaDB mit dem Schreiben einer Sitzung in einen Deadlock geriet.
 *
 * Treiber `database`: IDs in Blöcken lesen und über den Primärschlüssel löschen (keine Bereichssperre auf dem Index
 * `last_activity`); ein Block mit Deadlock wird wiederholt. Treiber `file`: Laravels Aufräumen der Dateien. Andere
 * Treiber (redis, memcached, cookie, array) lassen Sitzungen selbst ablaufen: übersprungen.
 */
final readonly class SessionsPruneTask implements TaskType
{
    public const string KEY = 'sessions_prune';

    public const int CHUNK = 500;

    public const int ATTEMPTS = 3;

    public function __construct(
        private SessionManager $sessions,
        private DatabaseManager $database,
        private DatabaseErrorClassifier $classifier,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('tasks.types.sessions_prune');
    }

    public function creatable(): bool
    {
        return false;
    }

    public function parameterFields(): array
    {
        return [];
    }

    public function describe(array $parameters): string
    {
        return __('tasks.describe_type.sessions_prune', ['minutes' => $this->lifetimeMinutes()]);
    }

    public function run(array $parameters): TaskResult
    {
        $driver = (string) config('session.driver');
        $lifetime = $this->lifetimeMinutes() * 60;

        $deleted = match ($driver) {
            'database' => $this->pruneDatabase(now()->getTimestamp() - $lifetime),
            'file' => $this->pruneFiles($lifetime),
            default => throw new TaskSkipped(__('tasks.sessions_prune.self_expiring', ['driver' => $driver])),
        };

        return new TaskResult(__('tasks.results.sessions_prune', ['deleted' => $deleted]), ['deleted' => $deleted, 'driver' => $driver]);
    }

    private function pruneDatabase(int $expiredBefore): int
    {
        $connection = $this->database->connection(config('session.connection'));
        $deleted = 0;

        do {
            $ids = $this->table($connection)->where('last_activity', '<=', $expiredBefore)->orderBy('id')->limit(self::CHUNK)->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            $deleted += $this->retryOnDeadlock(fn (): int => $this->table($connection)
                ->whereIn('id', $ids)
                ->where('last_activity', '<=', $expiredBefore)
                ->delete());
        } while (count($ids) === self::CHUNK);

        return $deleted;
    }

    private function pruneFiles(int $lifetime): int
    {
        $handler = $this->sessions->driver('file')->getHandler();

        return $handler instanceof FileSessionHandler ? $handler->gc($lifetime) : 0;
    }

    /** @param  callable(): int  $delete */
    private function retryOnDeadlock(callable $delete): int
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $delete();
            } catch (Throwable $e) {
                if ($attempt >= self::ATTEMPTS || $this->classifier->classify($e) !== DatabaseErrorType::Concurrency) {
                    throw $e;
                }

                usleep(100_000 * $attempt);
            }
        }
    }

    private function table(ConnectionInterface $connection): Builder
    {
        return $connection->table((string) config('session.table'))->useWritePdo();
    }

    private function lifetimeMinutes(): int
    {
        return max(1, (int) config('session.lifetime'));
    }
}
