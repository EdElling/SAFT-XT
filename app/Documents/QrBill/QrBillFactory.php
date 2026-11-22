<?php

declare(strict_types=1);

namespace App\Documents\QrBill;

use App\Documents\Letterhead;
use App\Documents\Templates\DocumentContext;
use App\Documents\Templates\TemplateEngine;
use App\Documents\Templates\TemplateException;
use Sprain\SwissQrBill\DataGroup\Element\AdditionalInformation;
use Sprain\SwissQrBill\DataGroup\Element\CreditorInformation;
use Sprain\SwissQrBill\DataGroup\Element\PaymentAmountInformation;
use Sprain\SwissQrBill\DataGroup\Element\PaymentReference;
use Sprain\SwissQrBill\DataGroup\Element\StructuredAddress;
use Sprain\SwissQrBill\QrBill;
use Sprain\SwissQrBill\Reference\QrPaymentReferenceGenerator;
use Sprain\SwissQrBill\Reference\RfCreditorReferenceGenerator;
use Throwable;

/**
 * QR-Rechnung (Swiss QR Bill) für einen Datensatz (B-124, D4a). Zahlungsempfänger aus dem Briefpapier (Firma, Strasse,
 * PLZ, Ort, Land; QR-IBAN, sonst IBAN), alles Übrige aus den Einstellungen der Vorlage mit Platzhaltern: Betrag,
 * Währung, Referenz, Mitteilung, Zahlungspflichtiger. Referenz: mit QR-IBAN eine QR-Referenz aus den Ziffern, sonst eine
 * RF-Referenz (ISO 11649), ohne Angabe keine. Ungültige Angaben ergeben eine TemplateException mit lesbarem Grund.
 *
 * Einstellungen (document_templates.qr_bill): enabled, position (page|bottom), amount, currency, reference, message,
 * debtor_name, debtor_street, debtor_building_number, debtor_postal_code, debtor_city, debtor_country.
 *
 * Nach IG QR-Rechnung 2.4: Hausnummer getrennt von der Strasse (ohne eigenes Feld vom Strassennamen abgetrennt);
 * QR-IBAN und QR-Referenz nur für CHF, in EUR die IBAN mit RF-Referenz.
 */
final class QrBillFactory
{
    public const string PAGE = 'page';

    public const string BOTTOM = 'bottom';

    public const array CURRENCIES = ['CHF', 'EUR'];

    /** Texte mit Platzhaltern (geprüft wie der Rest der Vorlage). */
    public const array TEXTS = ['amount', 'reference', 'message', 'debtor_name', 'debtor_street', 'debtor_building_number', 'debtor_postal_code', 'debtor_city', 'debtor_country'];

    /** Strassenangaben ohne Hausnummer, die nicht getrennt werden (Postfach mit Nummer). */
    private const string POST_BOX = '/^(postfach|case postale|casella postale|p\.?\s?o\.?\s?box|cp)\b/iu';

    public function __construct(private readonly TemplateEngine $engine) {}

    /** @param  array<string, mixed>|null  $settings */
    public static function enabled(?array $settings): bool
    {
        return (bool) ($settings['enabled'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $settings
     *
     * @throws TemplateException
     */
    public function build(array $settings, DocumentContext $context, Letterhead $letterhead): QrBill
    {
        $text = fn (string $key): string => trim((string) preg_replace('/\s+/u', ' ', $this->engine->text((string) ($settings[$key] ?? ''), $context)));
        $sender = self::creditor($letterhead->sender);
        $missing = array_keys(array_filter([
            __('documents.settings.sender_fields.firma') => blank($sender['firma'] ?? null),
            __('documents.settings.sender_fields.plz') => blank($sender['plz'] ?? null),
            __('documents.settings.sender_fields.ort') => blank($sender['ort'] ?? null),
            __('documents.settings.sender_fields.iban') => blank($sender['iban'] ?? null) && blank($sender['qr_iban'] ?? null),
        ]));

        if ($missing !== []) {
            throw new TemplateException(__('documents.qr.letterhead_missing', ['fields' => implode(', ', $missing)]));
        }

        $currency = in_array($settings['currency'] ?? 'CHF', self::CURRENCIES, true) ? (string) ($settings['currency'] ?? 'CHF') : 'CHF';
        // QR-IBAN nur für CHF (IG 2.4): in EUR die normale IBAN.
        $iban = self::normalizeIban((string) ($currency === 'CHF' && filled($sender['qr_iban'] ?? null) ? $sender['qr_iban'] : ($sender['iban'] ?? '')));

        if ($currency !== 'CHF' && ($iban === '' || self::isQrIban($iban))) {
            throw new TemplateException(__('documents.qr.eur_qr_iban'));
        }

        if (! self::ibanValid($iban)) {
            throw new TemplateException(__('documents.qr.iban', ['iban' => $iban]));
        }

        if (! in_array(substr($iban, 0, 2), ['CH', 'LI'], true)) {
            throw new TemplateException(__('documents.qr.iban_country', ['iban' => $iban]));
        }

        $qrBill = QrBill::create();
        $qrBill->setCreditor(self::address((string) $sender['firma'], (string) ($sender['strasse'] ?? ''), (string) ($sender['hausnummer'] ?? ''), (string) $sender['plz'], (string) $sender['ort'], (string) ($sender['land'] ?? '')));
        $creditor = CreditorInformation::create($iban);
        $qrBill->setCreditorInformation($creditor);
        $qrBill->setPaymentAmountInformation(PaymentAmountInformation::create($currency, self::amount($text('amount'))));
        $qrBill->setPaymentReference($this->reference($text('reference'), $creditor->containsQrIban()));

        if (($message = $text('message')) !== '') {
            $qrBill->setAdditionalInformation(AdditionalInformation::create(mb_substr($message, 0, 140)));
        }

        if ($text('debtor_name') !== '' && $text('debtor_postal_code') !== '' && $text('debtor_city') !== '') {
            $qrBill->setUltimateDebtor(self::address($text('debtor_name'), $text('debtor_street'), $text('debtor_building_number'), $text('debtor_postal_code'), $text('debtor_city'), $text('debtor_country')));
        }

        if (! $qrBill->isValid()) {
            $reasons = [];

            foreach ($qrBill->getViolations() as $violation) {
                $reasons[] = self::label((string) $violation->getPropertyPath()).': '.$violation->getMessage();
            }

            throw new TemplateException(__('documents.qr.invalid', ['reasons' => implode(' · ', array_unique($reasons))]));
        }

        return $qrBill;
    }

    /** IBAN ohne Leerzeichen, in Grossbuchstaben. */
    public static function normalizeIban(string $iban): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $iban));
    }

    /** Aufbau und Prüfziffer einer IBAN (ISO 13616, Modulo 97). */
    public static function ibanValid(string $iban): bool
    {
        $iban = self::normalizeIban($iban);

        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban) !== 1) {
            return false;
        }

        $digits = (string) preg_replace_callback('/[A-Z]/', static fn (array $m): string => (string) (ord($m[0]) - 55), substr($iban, 4).substr($iban, 0, 4));
        $rest = 0;

        foreach (str_split($digits, 7) as $chunk) {
            $rest = (int) ($rest.$chunk) % 97;
        }

        return $rest === 1;
    }

    /** QR-IBAN: Schweiz oder Liechtenstein, Institutsnummer (Stellen 5–9) 30000–31999. */
    public static function isQrIban(string $iban): bool
    {
        $iban = self::normalizeIban($iban);
        $iid = (int) substr($iban, 4, 5);

        return self::ibanValid($iban) && in_array(substr($iban, 0, 2), ['CH', 'LI'], true) && $iid >= 30000 && $iid <= 31999;
    }

    /**
     * Zahlungsempfänger: Strasse, PLZ und Ort aus den Feldern für die QR-Rechnung; sind sie leer, aus der Adresse des
     * Briefpapiers, wenn deren letzte Zeile „PLZ Ort“ ist (davor die Strasse) – so genügt ein bestehendes Briefpapier.
     *
     * @param  array<string, string|null>  $sender
     * @return array<string, string|null>
     */
    public static function creditor(array $sender): array
    {
        if (filled($sender['plz'] ?? null) || filled($sender['ort'] ?? null) || ($parsed = self::parseAddress($sender['adresse'] ?? null)) === null) {
            return $sender;
        }

        return [...$sender, 'strasse' => $sender['strasse'] ?? $parsed['street'], 'plz' => $parsed['postal_code'], 'ort' => $parsed['city'], 'land' => $sender['land'] ?? $parsed['country']];
    }

    /**
     * Adresse aus freiem Text: letzte Zeile „PLZ Ort“ (auch „CH-8000 Zürich“), die Zeile davor die Strasse.
     *
     * @return array{street: ?string, postal_code: string, city: string, country: ?string}|null
     */
    public static function parseAddress(?string $address): ?array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R|,/u', (string) $address) ?: []), static fn (string $l): bool => $l !== ''));
        $last = array_key_last($lines);

        if ($last === null || preg_match('/^(?:([A-Z]{2})[- ])?(\d{4,5})\s+(.+)$/u', $lines[$last], $match) !== 1) {
            return null;
        }

        return ['street' => $last > 0 ? $lines[$last - 1] : null, 'postal_code' => $match[2], 'city' => $match[3], 'country' => $match[1] !== '' ? $match[1] : null];
    }

    /**
     * Strasse und Hausnummer aus einem Feld: Nummer am Ende („Bahnhofstrasse 12a“, „Hauptstrasse 3-5“) oder am Anfang
     * („12, rue du Lac“). Ohne erkennbare Nummer (oder bei einem Postfach) bleibt alles im Strassenfeld.
     *
     * @return array{0: string, 1: ?string}
     */
    public static function splitStreet(string $street): array
    {
        $street = trim((string) preg_replace('/\s+/u', ' ', $street));
        $number = '\d{1,5}(?:\s?[a-zA-Z])?(?:\s?[-\/]\s?\d{1,5}(?:\s?[a-zA-Z])?)?';

        if ($street === '' || preg_match(self::POST_BOX, $street) === 1) {
            return [$street, null];
        }

        if (preg_match('/^(.*\pL.*?)[\s,]+('.$number.')$/u', $street, $match) === 1) {
            return [rtrim($match[1], ' ,'), $match[2]];
        }

        if (preg_match('/^('.$number.')[\s,]+(\pL.*)$/u', $street, $match) === 1) {
            return [$match[2], $match[1]];
        }

        return [$street, null];
    }

    /** Strukturierte Adresse mit getrennter Hausnummer (ohne Angabe aus der Strasse abgetrennt), Land ohne Angabe CH. */
    private static function address(string $name, string $street, string $number, string $postalCode, string $city, string $country): StructuredAddress
    {
        $country = strtoupper(trim($country)) ?: 'CH';
        $country = in_array($country, ['SCHWEIZ', 'SUISSE', 'SVIZZERA', 'SWITZERLAND'], true) ? 'CH' : $country;
        [$street, $parsed] = trim($number) === '' ? self::splitStreet($street) : [trim($street), trim($number)];

        return $street === ''
            ? StructuredAddress::createWithoutStreet(mb_substr($name, 0, 70), $postalCode, $city, $country)
            : StructuredAddress::createWithStreet(mb_substr($name, 0, 70), mb_substr($street, 0, 70), $parsed !== null ? mb_substr($parsed, 0, 16) : null, $postalCode, $city, $country);
    }

    /** Betrag aus dem Text: „1'234.50“, „1234,50“; leer = Feld zum Ausfüllen. */
    private static function amount(string $value): ?float
    {
        $clean = str_replace(["'", '’', ' ', "\u{00A0}"], '', $value);

        if ($clean === '') {
            return null;
        }

        if (preg_match('/^\d+,\d{1,2}$/', $clean) === 1) {
            $clean = str_replace(',', '.', $clean);
        }

        if (! is_numeric($clean)) {
            throw new TemplateException(__('documents.qr.amount', ['value' => $value]));
        }

        return round((float) $clean, 2);
    }

    /** @throws TemplateException */
    private function reference(string $value, bool $qrIban): PaymentReference
    {
        try {
            if ($qrIban) {
                $digits = (string) preg_replace('/\D/', '', $value);

                if ($digits === '' || strlen($digits) > 26) {
                    throw new TemplateException(__('documents.qr.qr_reference', ['value' => $value]));
                }

                return PaymentReference::create(PaymentReference::TYPE_QR, QrPaymentReferenceGenerator::generate(null, $digits));
            }

            $alnum = (string) preg_replace('/[^A-Za-z0-9]/', '', $value);

            return $alnum === ''
                ? PaymentReference::create(PaymentReference::TYPE_NON)
                : PaymentReference::create(PaymentReference::TYPE_SCOR, RfCreditorReferenceGenerator::generate(substr($alnum, 0, 21)));
        } catch (TemplateException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new TemplateException(__('documents.qr.reference', ['value' => $value, 'message' => $exception->getMessage()]), previous: $exception);
        }
    }

    /** Bezeichnung zum Pfad einer Verletzung (creditorInformation.iban → IBAN). */
    private static function label(string $path): string
    {
        $key = match (true) {
            str_starts_with($path, 'creditorInformation') => 'iban',
            str_starts_with($path, 'creditor') => 'creditor',
            str_starts_with($path, 'ultimateDebtor') => 'debtor',
            str_starts_with($path, 'paymentAmountInformation') => 'amount',
            str_starts_with($path, 'paymentReference') => 'reference',
            str_starts_with($path, 'additionalInformation') => 'message',
            default => null,
        };

        return $key === null ? $path : __('documents.qr.parts.'.$key);
    }
}
