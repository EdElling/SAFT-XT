<?php

declare(strict_types=1);

namespace App\Plugins\Api;

use App\Apps\CurrentApp;
use App\DynamicData\Write\RecordWriter;
use App\DynamicData\Write\WriteOptions;
use App\Filament\Resources\WorkItems\WorkItemResource;
use App\Models\CustomTable;
use App\Models\User;
use App\Plugins\PluginRegistry;
use App\Plugins\SaftPlugin;
use App\Work\WorkService;
use Closure;
use Filament\Notifications\Notification;
use InvalidArgumentException;

/**
 * Einstieg der Programmierschnittstelle für Plugins: Zugriff auf Tabellen des Table-Designers (und lesend auf
 * angebundene Tabellen) mit den Rechten, Prüfregeln und dem Änderungsprotokoll von SAFT.
 *
 *     Saft::table('lager_bestaende')->query()->where('artikel_id', $id)->get();
 *
 * @api
 */
final class Saft
{
    /** Gibt es die Tabelle (Table-Designer oder angebunden)? Für Hinweise statt Fehlern. */
    public static function hasTable(string $name): bool
    {
        return CustomTable::query()->where('name', $name)->exists();
    }

    /** @throws InvalidArgumentException wenn es die Tabelle nicht gibt */
    public static function table(string $name): DataTable
    {
        $table = CustomTable::query()->with('fields')->where('name', $name)->first()
            ?? throw new InvalidArgumentException("Tabelle „{$name}“ gibt es nicht.");

        return new DataTable($table);
    }

    /**
     * Ein installiertes Plugin, z. B. das eigene für missingDependencies().
     *
     * @throws InvalidArgumentException wenn es nicht installiert ist
     */
    public static function plugin(string $id): SaftPlugin
    {
        return app(PluginRegistry::class)->get($id);
    }

    /**
     * Benachrichtigung in die Glocke einer Person – sofort, ohne Queue-Worker (Filaments sendToDatabase() stellt sie in
     * die Warteschlange; SAFT läuft ohne Worker).
     */
    public static function notify(User $user, Notification $notification): void
    {
        $user->notifyNow($notification->toDatabase());
    }

    /**
     * Mehrere Änderungen an Tabellen des Table-Designers als Einheit: alles oder nichts.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function transaction(Closure $callback): mixed
    {
        return app(RecordWriter::class)->batch($callback, WriteOptions::plugin());
    }

    /**
     * Eine Arbeit starten (seit API 1.10): als die angemeldete Person in ihrer aktuellen App. Liefert die Adresse der
     * Arbeit – dorthin weiterleiten, dann treibt der Browser der Person sie voran und zeigt den Fortschritt; verlässt sie
     * die Seite, übernimmt der Scheduler.
     *
     * @param  string  $type  Kennung der Art (WorkType::key(), aus SaftPlugin::workTypes())
     * @param  array<string, mixed>  $parameters  JSON-fähig; kommen in prepare() und WorkStep::$parameters an
     *
     * @throws InvalidArgumentException wenn es die Art nicht gibt
     */
    public static function startWork(string $type, array $parameters = []): string
    {
        $user = auth()->user();
        $user = $user instanceof User ? $user : null;
        $item = app(WorkService::class)->start($type, $parameters, $user, app(CurrentApp::class)->id($user));

        return WorkItemResource::getUrl('view', ['record' => $item], panel: 'admin');
    }
}
