<?php

declare(strict_types=1);

namespace App\Plugins\Context;

use App\Plugins\Api\ApiManifest;
use App\Plugins\Testing\PluginTestCase;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;

/**
 * Was die Plugin-API zusichert, per Reflection aus den Klassen in ApiManifest: öffentliche und geschützte Methoden,
 * öffentliche Eigenschaften und Konstanten, die SAFT selbst festlegt (nicht Laravel, Filament oder PHP), ohne
 *
 * @internal. Grundlage für den Vertragstest (signatures()) und die API-Referenz im Entwickler-Kontext (classes()).
 */
final class ApiSurface
{
    /**
     * @return array<string, array{kind: string, parent: ?string, doc: string, members: list<array{signature: string, doc: string}>}>
     *                                                                                                                                nach Klassenname; TYPES nur mit kind „type“
     */
    public static function classes(): array
    {
        $classes = [];

        foreach (ApiManifest::CLASSES as $class) {
            // Die Test-Basis gibt es nur mit den Dev-Paketen (Produktion: composer install --no-dev).
            if ($class === PluginTestCase::class && ! class_exists('Tests\\TestCase')) {
                $classes[$class] = ['kind' => 'abstract class', 'parent' => 'Tests\\TestCase', 'doc' => 'Only available in development installations.', 'members' => []];

                continue;
            }

            $reflection = new ReflectionClass($class);
            $members = [];

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
                if (str_contains((string) $method->getDocComment(), '@internal')
                    || (! str_starts_with($method->getDeclaringClass()->getName(), 'App\\') && ! $reflection->isTrait())
                    // Methoden aus Traits von Laravel oder den Tests von SAFT (z. B. RefreshDatabase) zählen nicht.
                    || ! str_starts_with((string) $method->getFileName(), app_path())) {
                    continue;
                }

                $members[] = ['signature' => self::method($method), 'doc' => self::doc($method->getDocComment())];
            }

            foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                if (str_starts_with($property->getDeclaringClass()->getName(), 'App\\')) {
                    $members[] = [
                        'signature' => 'property '.($property->isStatic() ? 'static ' : '').self::type($property->getType()).' $'.$property->getName(),
                        'doc' => self::doc($property->getDocComment()),
                    ];
                }
            }

            foreach ($reflection->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
                if (str_starts_with($constant->getDeclaringClass()->getName(), 'App\\')) {
                    $members[] = [
                        'signature' => 'const '.$constant->getName().' = '.json_encode($constant->getValue(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'doc' => self::doc($constant->getDocComment()),
                    ];
                }
            }

            usort($members, static fn (array $a, array $b): int => strcmp($a['signature'], $b['signature']));
            $parent = $reflection->getParentClass();
            $classes[$class] = [
                'kind' => $reflection->isTrait() ? 'trait' : ($reflection->isInterface() ? 'interface' : ($reflection->isAbstract() ? 'abstract class' : 'class')),
                'parent' => $parent === false ? null : $parent->getName(),
                'doc' => self::doc($reflection->getDocComment()),
                'members' => $members,
            ];
        }

        foreach (ApiManifest::TYPES as $type) {
            $classes[$type] = ['kind' => 'type', 'parent' => null, 'doc' => '', 'members' => []];
        }

        ksort($classes);

        return $classes;
    }

    /** @return array<string, list<string>> Klasse => Signaturen (Momentaufnahme des Vertragstests) */
    public static function signatures(): array
    {
        return array_map(static fn (array $c): array => $c['kind'] === 'type'
            ? ['type']
            : [$c['kind'].($c['parent'] === null ? '' : ' extends '.$c['parent']), ...array_column($c['members'], 'signature')],
            self::classes());
    }

    private static function method(ReflectionMethod $method): string
    {
        $parameters = implode(', ', array_map(static fn (ReflectionParameter $p): string => trim(
            self::type($p->getType()).' '.($p->isVariadic() ? '...' : '').'$'.$p->getName()
            .($p->isDefaultValueAvailable() ? ' = '.json_encode($p->getDefaultValue(), JSON_UNESCAPED_UNICODE) : '')
        ), $method->getParameters()));

        return trim(($method->isProtected() ? 'protected ' : '').($method->isAbstract() ? 'abstract ' : '').($method->isFinal() ? 'final ' : '').($method->isStatic() ? 'static ' : '')
            .'function '.$method->getName().'('.$parameters.')'.($method->hasReturnType() ? ': '.self::type($method->getReturnType()) : ''));
    }

    private static function type(?ReflectionType $type): string
    {
        if ($type === null) {
            return '';
        }

        return $type instanceof ReflectionNamedType && $type->allowsNull() && $type->getName() !== 'mixed' && $type->getName() !== 'null'
            ? '?'.$type->getName()
            : (string) $type;
    }

    /** Doc-Kommentar ohne Kommentarzeichen und ohne @api; Zeilen bleiben erhalten. */
    private static function doc(string|false $comment): string
    {
        if ($comment === false) {
            return '';
        }

        $lines = array_map(static fn (string $line): string => rtrim((string) preg_replace('/^\s*(\/\*\*|\*\/|\*)\s?/', '', $line)), explode("\n", $comment));
        $lines = array_filter($lines, static fn (string $line): bool => trim($line) !== '' && trim($line) !== '*/' && ! str_starts_with(trim($line), '@api'));

        return trim((string) preg_replace('/\s*\*\/$/', '', implode("\n", $lines)));
    }
}
