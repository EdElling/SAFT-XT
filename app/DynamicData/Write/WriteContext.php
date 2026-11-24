<?php

declare(strict_types=1);

namespace App\DynamicData\Write;

use App\Models\DynamicRecord;
use Closure;
use SplObjectStorage;
use Throwable;

/**
 * Zustand des laufenden Schreibvorgangs je Prozess: die aktiven Optionen (verschachtelte Aufrufe erben sie, es gibt
 * nur eine Transaktion) und die Datensätze, die der Writer gerade in die Datenbank schreibt. Solange ein Datensatz
 * darin steht, führt DynamicRecord::save()/delete() den Eloquent-Schreibvorgang selbst aus; sonst leitet das Model
 * an den RecordWriter weiter. Dazu die Wirkungen nach aussen (afterCommit), die erst nach dem Commit des äussersten
 * Schreibvorgangs laufen dürfen.
 */
final class WriteContext
{
    /** @var list<WriteOptions> */
    private static array $stack = [];

    /** @var SplObjectStorage<DynamicRecord, bool>|null Datensätze, die der Writer gerade schreibt => wird angelegt? */
    private static ?SplObjectStorage $performing = null;

    /** Ereignisse für Plugins vorübergehend aus (DynamicRecord::withoutPluginEvents()). */
    private static bool $pluginEventsMuted = false;

    /** @var list<Closure(): void> Wirkungen nach aussen, die auf den Commit des äussersten Schreibvorgangs warten. */
    private static array $afterCommit = [];

    public static function active(): bool
    {
        return self::$stack !== [];
    }

    public static function options(): WriteOptions
    {
        return self::$stack[array_key_last(self::$stack)] ?? WriteOptions::defaults();
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(WriteOptions $options, Closure $callback): mixed
    {
        self::$stack[] = $options;

        try {
            return $callback();
        } finally {
            array_pop(self::$stack);
        }
    }

    /** Schreibt der Writer diesen Datensatz gerade selbst? */
    public static function performing(DynamicRecord $record): bool
    {
        return self::$performing?->contains($record) ?? false;
    }

    /** Wird der Datensatz, den der Writer gerade schreibt, neu angelegt? */
    public static function creating(DynamicRecord $record): bool
    {
        return (self::$performing?->contains($record) ?? false) && self::$performing[$record] === true;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     */
    public static function perform(DynamicRecord $record, Closure $write, bool $creating = false): mixed
    {
        self::$performing ??= new SplObjectStorage;
        self::$performing->attach($record, $creating);

        try {
            return $write();
        } finally {
            self::$performing->detach($record);
        }
    }

    /**
     * Wirkung nach aussen (E-Mail, Webhook, Aufruf eines Dienstes) erst, wenn der äusserste Schreibvorgang committet
     * ist (Fundament 2, F3b): Ein zurückgerollter Block darf keine Spuren hinterlassen. Ohne laufenden Schreibvorgang
     * läuft der Callback sofort.
     *
     * @param  Closure(): void  $callback
     */
    public static function afterCommit(Closure $callback): void
    {
        if (! self::active()) {
            $callback();

            return;
        }

        self::$afterCommit[] = $callback;
    }

    /**
     * Nach dem Commit: alle wartenden Wirkungen ausführen, auch wenn eine davon scheitert; der erste Fehler wird danach
     * weitergereicht.
     *
     * @internal nur für den RecordWriter
     */
    public static function flushAfterCommit(): void
    {
        $callbacks = self::$afterCommit;
        self::$afterCommit = [];
        $first = null;

        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (Throwable $e) {
                $first ??= $e;
            }
        }

        if ($first !== null) {
            throw $first;
        }
    }

    /** @internal nur für den RecordWriter: nach einem Rollback verfallen die wartenden Wirkungen. */
    public static function discardAfterCommit(): void
    {
        self::$afterCommit = [];
    }

    public static function pluginEventsEnabled(): bool
    {
        return ! self::$pluginEventsMuted && self::options()->pluginEvents;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function withoutPluginEvents(Closure $callback): mixed
    {
        $muted = self::$pluginEventsMuted;
        self::$pluginEventsMuted = true;

        try {
            return $callback();
        } finally {
            self::$pluginEventsMuted = $muted;
        }
    }
}
