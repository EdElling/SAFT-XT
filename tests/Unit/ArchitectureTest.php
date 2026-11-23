<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Architekturregeln, die die Maschine prüft (Fundament 2, F0; Architektur-Review 4.5 und 4.8).
 *
 * 1. Raw-SQL nur in den Dialektklassen (DDL, Auswertungen), im Dumper und an begründeten Ausnahmen.
 * 2. Ob eine Person Super-Admin ist, entscheidet allein User::isSuperAdmin(); kein hasRole() oder
 *    Rollenvergleich am Namen anderswo – jede solche Stelle wäre eine Lücke in „Ansicht als Rolle“.
 *
 * Eine neue Ausnahme braucht einen Grund in der Liste unten; der Zähler je Datei verhindert, dass
 * eine erlaubte Datei unbemerkt weitere Stellen bekommt.
 */
class ArchitectureTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../';

    /** Verzeichnisse, in denen Raw-SQL zu Hause ist: Dialekte je Datenbank und der Dumper. */
    private const array RAW_SQL_HOMES = ['app/DynamicData/Ddl/', 'app/Reports/Dialects/', 'app/Database/'];

    /** @var array<string, array{0: int, 1: string}> Datei => [höchstens so viele Stellen, Grund] */
    private const array RAW_SQL_EXCEPTIONS = [
        'app/Reports/ReportQuery.php' => [3, 'Ausdruck kommt aus dem Abfrage-Dialekt; nur der Alias wird über die Grammar angehängt'],
        'app/Services/DynamicSchemaService.php' => [1, 'havingRaw(count(*) > 1): Standard-SQL, der Query Builder kennt kein having auf Aggregaten ohne Raw'],
        'app/Navigation/MenuNavigationBuilder.php' => [1, 'count(*) und max(updated_at) als ein Fingerabdruck in einer Abfrage; Standard-SQL'],
        'app/DynamicData/Eloquent/OptionalSoftDeletingScope.php' => [1, 'whereRaw(1 = 0): leere Menge, Standard-SQL'],
        'database/seeders/ExternalResearchDatabase.php' => [3, 'Testdatenbank für den Table-Connector: Schema und Tabellen je Dialekt'],
        'database/seeders/MySqlResearchDatabase.php' => [2, 'Testdatenbank für den Table-Connector (MySQL/MariaDB)'],
        'database/seeders/SqlServerResearchDatabase.php' => [6, 'Testdatenbank für den Table-Connector (SQL Server)'],
    ];

    private const string RAW_SQL_PATTERN = '/DB::(raw|statement|unprepared|select|selectOne|scalar|insert|update|delete|affectingStatement)\(|->(raw|statement|unprepared|affectingStatement|selectRaw|whereRaw|orWhereRaw|havingRaw|orHavingRaw|orderByRaw|groupByRaw|fromRaw|whereIntegerInRaw)\(/';

    /** Erkennung einer Person als Super-Admin über Rollennamen. */
    private const string SUPER_ADMIN_PATTERN = '/hasRole\(|hasAnyRole\(|hasAllRoles\(|hasExactRoles\(|roles\(\)->where\(|::role\(\s*User::ROLE_SUPER_ADMIN|[\'"]super-admin[\'"]/';

    /** @var array<string, array{0: int, 1: string}> */
    private const array SUPER_ADMIN_EXCEPTIONS = [
        'app/Models/User.php' => [2, 'Konstante ROLE_SUPER_ADMIN und isSuperAdmin() selbst'],
        'app/Auth/RoleSimulation.php' => [1, 'canSimulate() muss die echten Rollen in der Datenbank lesen, während die geladenen Rollen simuliert sind'],
    ];

    public function test_raw_sql_only_in_dialects_and_listed_exceptions(): void
    {
        $this->assertRule(self::RAW_SQL_PATTERN, self::RAW_SQL_HOMES, self::RAW_SQL_EXCEPTIONS,
            'Raw-SQL ausserhalb der Dialekte (CLAUDE.md: Datenbank-Portabilität). Query Builder nutzen oder Dialektklasse erweitern');
    }

    public function test_super_admin_is_only_detected_by_user_is_super_admin(): void
    {
        $this->assertRule(self::SUPER_ADMIN_PATTERN, [], self::SUPER_ADMIN_EXCEPTIONS,
            'Super-Admin-Erkennung über den Rollennamen: User::isSuperAdmin() bzw. User::superAdmins() nutzen');
    }

    /**
     * @param  list<string>  $homes
     * @param  array<string, array{0: int, 1: string}>  $exceptions
     */
    private function assertRule(string $pattern, array $homes, array $exceptions, string $message): void
    {
        $violations = [];
        $unused = $exceptions;

        foreach ($this->phpFiles() as $file) {
            $hits = $this->hits($file, $pattern);

            if ($hits === [] || $this->inHome($file, $homes)) {
                continue;
            }

            if (isset($exceptions[$file])) {
                unset($unused[$file]);

                if (count($hits) <= $exceptions[$file][0]) {
                    continue;
                }

                $violations[] = "{$file}: ".count($hits)." Stellen, erlaubt {$exceptions[$file][0]} – ".implode(', ', $hits);

                continue;
            }

            $violations[] = "{$file}: ".implode(', ', $hits);
        }

        $this->assertSame([], $violations, "{$message}:\n- ".implode("\n- ", $violations));
        $this->assertSame([], $unused, 'Ausnahmen ohne Treffer – aus der Liste streichen: '.implode(', ', array_keys($unused)));
    }

    /** @return list<string> Zeilennummern als „Zeile n“ */
    private function hits(string $file, string $pattern): array
    {
        $hits = [];

        foreach (file(self::ROOT.$file) ?: [] as $number => $line) {
            if (preg_match($pattern, $line) === 1) {
                $hits[] = 'Zeile '.($number + 1);
            }
        }

        return $hits;
    }

    /** @param  list<string>  $homes */
    private function inHome(string $file, array $homes): bool
    {
        return array_any($homes, static fn (string $home): bool => str_starts_with($file, $home));
    }

    /** @return list<string> Pfade relativ zum Projekt unter app/ und database/ */
    private function phpFiles(): array
    {
        $files = [];

        foreach (['app', 'database'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT.$dir, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $info) {
                if ($info->getExtension() === 'php') {
                    $files[] = substr($info->getPathname(), strlen(self::ROOT));
                }
            }
        }

        sort($files);

        return $files;
    }
}
