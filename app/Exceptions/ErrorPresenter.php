<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Exceptions\Database\DatabaseErrorClassifier;
use App\Exceptions\Database\DatabaseErrorType;
use App\Models\CustomTable;
use App\Models\DatabaseConnection;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use WeakMap;

/**
 * Zentrale Abbildung: Throwable -> UserFacingError.
 *
 * Einzige Stelle, die entscheidet, was Endnutzer über einen Fehler erfahren.
 * Wird von Filament/Livewire, JSON- und HTML-Rendering gemeinsam genutzt.
 * Als Singleton registriert, damit Log und Antwort dieselbe Referenz tragen.
 */
final class ErrorPresenter
{
    /** @var WeakMap<Throwable, string> */
    private WeakMap $references;

    public function __construct(
        private readonly DatabaseErrorClassifier $classifier,
        private readonly bool $debug = false,
    ) {
        $this->references = new WeakMap;
    }

    public function present(Throwable $e): UserFacingError
    {
        $error = $this->map($e);

        if (! $this->debug) {
            return $error;
        }

        return new UserFacingError(
            code: $error->code,
            title: $error->title,
            message: $error->message,
            status: $error->status,
            reference: $error->reference,
            fields: $error->fields,
            expected: $error->expected,
            debug: array_filter([
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'sql' => $e instanceof QueryException ? $e->getSql() : null,
            ]),
        );
    }

    /**
     * Stabile Referenz je Exception-Instanz (für Log-Kontext und Anzeige).
     * Nur für Fehler, die der Support nachverfolgen muss; sonst null.
     */
    public function referenceFor(Throwable $e): ?string
    {
        if (! $this->needsReference($e)) {
            return null;
        }

        return $this->references[$e] ??= Str::upper(Str::random(8));
    }

    /** Erwartete Fehler (Nutzer kann sie selbst beheben) werden nicht als Error geloggt. */
    public function isExpected(Throwable $e): bool
    {
        return $this->map($e)->expected;
    }

    private function map(Throwable $e): UserFacingError
    {
        if ($e instanceof ValidationException) {
            return $this->make('validation', 422, __('errors.validation'), expected: true, fields: $e->errors());
        }

        if ($e instanceof BusinessRuleException) {
            return new UserFacingError(
                code: $e->errorCode,
                title: $e->title ?? __('errors.titles.business_rule'),
                message: $e->getMessage(),
                status: 422,
                expected: true,
            );
        }

        if (($dbType = $this->classifier->classify($e)) !== null) {
            return $this->fromDatabase($e, $dbType);
        }

        $status = match (true) {
            $e instanceof AuthenticationException => 401,
            $e instanceof AuthorizationException => 403,
            $e instanceof ModelNotFoundException => 404,
            $e instanceof TokenMismatchException => 419,
            $e instanceof HttpExceptionInterface => $e->getStatusCode(),
            default => 500,
        };

        if ($status >= 500) {
            return $this->make('server_error', $status, $this->httpMessage($status), reference: $this->referenceFor($e));
        }

        return $this->make("http.{$status}", $status, $this->httpMessage($status), expected: true);
    }

    private function fromDatabase(Throwable $e, DatabaseErrorType $type): UserFacingError
    {
        $message = match ($type) {
            DatabaseErrorType::UniqueViolation => $this->uniqueMessage($this->classifier->uniqueColumns($e)),
            DatabaseErrorType::CheckViolation => $this->checkMessage($e),
            default => __('errors.db.'.Str::after($type->value, 'db.')),
        };

        return new UserFacingError(
            code: $type->value,
            title: __($type->isExpected() ? 'errors.titles.conflict' : 'errors.titles.database'),
            message: $message,
            status: $type->status(),
            reference: $this->referenceFor($e),
            expected: $type->isExpected(),
        );
    }

    /**
     * CHECK-Regel einer angebundenen Tabelle: betroffene Felder und Name der Regel nennen.
     */
    private function checkMessage(Throwable $e): string
    {
        $name = $this->classifier->checkConstraintName($e);
        $connection = $e instanceof QueryException ? (string) $e->connectionName : '';

        if ($name === null || ! str_starts_with($connection, DatabaseConnection::CONNECTION_PREFIX)) {
            return __('errors.db.check_violation');
        }

        try {
            $tables = CustomTable::query()->external()->with('fields')
                ->where('database_connection_id', (int) substr($connection, strlen(DatabaseConnection::CONNECTION_PREFIX)))
                ->get();
        } catch (Throwable) {
            return __('errors.db.check_violation');
        }

        foreach ($tables as $table) {
            foreach ((array) ($table->check_constraints ?? []) as $check) {
                if (($check['name'] ?? null) !== $name) {
                    continue;
                }

                $labels = collect((array) ($check['columns'] ?? []))
                    ->map(fn (string $column): string => (string) ($table->fields->firstWhere('name', $column)?->label ?? $column))
                    ->all();

                return trans_choice('errors.db.check_violation_named', count($labels), [
                    'fields' => '„'.implode('“, „', $labels).'“',
                    'rule' => $name,
                ]);
            }
        }

        return __('errors.db.check_violation_rule', ['rule' => $name]);
    }

    /**
     * @param  list<string>  $columns
     */
    private function uniqueMessage(array $columns): string
    {
        if ($columns === []) {
            return __('errors.db.unique_violation');
        }

        $labels = array_map($this->attributeLabel(...), $columns);

        return trans_choice('errors.db.unique_violation_fields', count($labels), [
            'fields' => '„'.implode('“, „', $labels).'“',
        ]);
    }

    private function attributeLabel(string $column): string
    {
        foreach (["errors.attributes.{$column}", "validation.attributes.{$column}"] as $key) {
            if (Lang::has($key)) {
                return (string) __($key);
            }
        }

        return Str::headline($column);
    }

    private function httpMessage(int $status): string
    {
        return Lang::has("errors.http.{$status}")
            ? (string) __("errors.http.{$status}")
            : (string) __($status >= 500 ? 'errors.http.500' : 'errors.http.400');
    }

    /**
     * @param  array<string, list<string>>  $fields
     */
    private function make(
        string $code,
        int $status,
        string $message,
        bool $expected = false,
        ?string $reference = null,
        array $fields = [],
    ): UserFacingError {
        $titleKey = "errors.titles.{$status}";

        return new UserFacingError(
            code: $code,
            title: Lang::has($titleKey) ? (string) __($titleKey) : (string) __('errors.titles.default'),
            message: $message,
            status: $status,
            reference: $reference,
            fields: $fields,
            expected: $expected,
        );
    }

    private function needsReference(Throwable $e): bool
    {
        if ($e instanceof ValidationException || $e instanceof BusinessRuleException) {
            return false;
        }

        if ($this->classifier->classify($e) !== null) {
            return true;
        }

        return ! ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500)
            && ! $e instanceof AuthenticationException
            && ! $e instanceof AuthorizationException
            && ! $e instanceof ModelNotFoundException
            && ! $e instanceof TokenMismatchException;
    }
}
