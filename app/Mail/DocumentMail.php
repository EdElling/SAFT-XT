<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Dokument als Anhang einer E-Mail (B-124, D3b): Text ohne HTML, Absenderadresse aus der .env (SPF/DKIM des Hosters),
 * Name aus dem Briefpapier. Wird sofort versendet – SAFT läuft ohne Queue-Worker.
 */
final class DocumentMail extends Mailable
{
    public function __construct(
        public readonly string $mailSubject,
        public readonly string $text,
        public readonly string $fileName,
        public readonly string $pdf,
        public readonly ?string $senderName = null,
        public readonly ?string $replyToAddress = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), filled($this->senderName) ? $this->senderName : (string) config('mail.from.name')),
            replyTo: $this->replyToAddress !== null ? [new Address($this->replyToAddress)] : [],
            subject: $this->mailSubject,
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.document', with: ['text' => $this->text]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn (): string => $this->pdf, $this->fileName)->withMime('application/pdf')];
    }
}
