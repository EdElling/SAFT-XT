<?php

declare(strict_types=1);

namespace App\Documents;

use App\Apps\AppPermissions;
use App\Documents\Templates\RenderedDocument;
use App\Documents\Templates\TemplateRenderer;
use App\Exceptions\BusinessRuleException;
use App\Mail\DocumentMail;
use App\Models\DocumentTemplate;
use App\Models\DynamicRecord;
use App\Models\User;
use App\Support\MailConnectionTester;
use Illuminate\Support\Facades\Mail;

/**
 * Dokument per E-Mail versenden (B-124, D3b): mit dem App-Recht „Dokumente: versenden“, wenn die Vorlage eine Adresse
 * vorsieht. Sofort über den Mailserver der .env, PDF als Anhang, auf Wunsch Kopie (BCC) an die Person. Jeder Versand
 * steht im Verlauf des Datensatzes (Ereignis „document_sent“: Vorlage, Datei, Prüfwert, Empfänger, Betreff).
 */
final class DocumentMailer
{
    public const string PERMISSION = 'documents.send';

    public const string EVENT = 'document_sent';

    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly LetterheadResolver $letterheads,
    ) {}

    /** Lesbare Meldung zu einem Fehler beim Versand; abgelehnte Absenderadresse mit Hinweis auf die Einstellungen. */
    public static function failure(\Throwable $exception): string
    {
        return MailConnectionTester::senderRejected($exception->getMessage())
            ? __('documents.mail.sender_rejected', ['from' => (string) config('mail.from.address')])
            : __('documents.mail.failed', ['message' => $exception->getMessage()]);
    }

    public static function canSend(?User $user, DocumentTemplate $template): bool
    {
        return $user !== null && filled($template->email_to) && AppPermissions::can($user, self::PERMISSION, $template->app_id);
    }

    /**
     * E-Mail aus der Vorlage für den Datensatz (Vorgaben im Dialog bzw. für den Sammelversand).
     *
     * @return array{to: list<string>, invalid: list<string>, subject: string, body: string, reply_to: ?string}|null
     */
    public function compose(DocumentTemplate $template, DynamicRecord $record, ?User $user): ?array
    {
        return $this->renderer->mail($template, $record, $user);
    }

    /**
     * @param  array{to: list<string>|string, subject: string, body: string, reply_to?: ?string}  $mail
     * @return list<string> Empfänger
     *
     * @throws BusinessRuleException ohne Recht oder ohne gültige Adresse
     */
    public function send(DocumentTemplate $template, DynamicRecord $record, ?User $user, RenderedDocument $document, string $fileName, string $sha256, array $mail, bool $copyToMe = false): array
    {
        if (! self::canSend($user, $template)) {
            throw new BusinessRuleException(__('documents.mail.not_allowed'), 'documents.mail_not_allowed');
        }

        [$to, $invalid] = is_array($mail['to']) ? [$mail['to'], []] : TemplateRenderer::addresses($mail['to']);

        if ($to === [] || $invalid !== []) {
            throw new BusinessRuleException(__('documents.mail.no_address', ['value' => implode(', ', $invalid)]), 'documents.mail_no_address');
        }

        $sender = (string) ($this->letterheads->for($template->app_id)->sender['firma'] ?? '');
        $replyTo = filled($mail['reply_to'] ?? null) ? (string) $mail['reply_to'] : null;
        $pending = Mail::to($to);
        $copy = $copyToMe && filled($user?->email) ? (string) $user->email : null;

        if ($copy !== null) {
            $pending->bcc($copy);
        }

        $pending->send(new DocumentMail(trim($mail['subject']) !== '' ? trim($mail['subject']) : $template->name, $mail['body'], $fileName, $document->pdf, $sender !== '' ? $sender : null, $replyTo));

        activity('data')
            ->causedBy($user)
            ->performedOn($record)
            ->event(self::EVENT)
            ->withChanges(['attributes' => array_filter([
                'vorlage' => $template->name,
                'datei' => $fileName,
                'sha256' => $sha256,
                'an' => implode(', ', $to),
                'betreff' => $mail['subject'],
                'kopie' => $copy,
            ], static fn (mixed $value): bool => $value !== null && $value !== '')])
            ->log(self::EVENT);

        return $to;
    }
}
