<?php

namespace Tests;

use App\DynamicData\DynamicDataNamespace;
use App\Models\DynamicRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Der Testclient meldet sonst „en-us“; die Tests prüfen die deutsche Oberfläche. */
    protected $defaultHeaders = ['Accept-Language' => 'de'];

    /**
     * MySQL beendet die Test-Transaktion auf app_data mit jeder DDL-Anweisung; die Verbindung
     * läuft dort ohne Test-Transaktion, setUp() leert den Namensraum stattdessen.
     */
    protected function setUpTraits()
    {
        // Vor RefreshDatabase: nie eine andere als eine Testdatenbank anfassen.
        TestDatabaseGuard::check();

        if (property_exists($this, 'connectionsToTransact')
            && ! app(DynamicDataNamespace::class)->dialect()->supportsTransactionalDdl()) {
            $this->connectionsToTransact = array_values(array_filter(
                $this->connectionsToTransact,
                static fn (?string $connection): bool => $connection !== config('dynamic_data.connection'),
            ));
        }

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // MySQL rollt DDL nicht mit der Test-Transaktion zurück: Tabellen des Table-Designers
        // aus früheren Tests entfernen.
        if (in_array(RefreshDatabase::class, class_uses_recursive($this), true)) {
            $namespace = app(DynamicDataNamespace::class);

            if (! $namespace->dialect()->supportsTransactionalDdl()) {
                $namespace->reset();
                DynamicRecord::forget();
            }
        }
    }

    /** Tabellenname im Namensraum des Table-Designers (PostgreSQL: app_data.x, MySQL: <DB>_app_data.x). */
    protected function qualified(string $table): string
    {
        return app(DynamicDataNamespace::class)->qualify($table);
    }
}
