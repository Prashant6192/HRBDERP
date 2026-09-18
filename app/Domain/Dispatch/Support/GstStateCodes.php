<?php

declare(strict_types=1);

namespace App\Domain\Dispatch\Support;

/**
 * The GST state codes: the first two characters of a GSTIN, and the place
 * of supply on an e-invoice.
 */
final class GstStateCodes
{
    /** @var array<string, string> */
    public const array NAMES = [
        '01' => 'Jammu & Kashmir', '02' => 'Himachal Pradesh', '03' => 'Punjab', '04' => 'Chandigarh',
        '05' => 'Uttarakhand', '06' => 'Haryana', '07' => 'Delhi', '08' => 'Rajasthan',
        '09' => 'Uttar Pradesh', '10' => 'Bihar', '11' => 'Sikkim', '12' => 'Arunachal Pradesh',
        '13' => 'Nagaland', '14' => 'Manipur', '15' => 'Mizoram', '16' => 'Tripura',
        '17' => 'Meghalaya', '18' => 'Assam', '19' => 'West Bengal', '20' => 'Jharkhand',
        '21' => 'Odisha', '22' => 'Chhattisgarh', '23' => 'Madhya Pradesh', '24' => 'Gujarat',
        '26' => 'Dadra & Nagar Haveli and Daman & Diu', '27' => 'Maharashtra', '29' => 'Karnataka',
        '30' => 'Goa', '31' => 'Lakshadweep', '32' => 'Kerala', '33' => 'Tamil Nadu',
        '34' => 'Puducherry', '35' => 'Andaman & Nicobar Islands', '36' => 'Telangana',
        '37' => 'Andhra Pradesh', '38' => 'Ladakh', '97' => 'Other Territory', '96' => 'Other Country',
    ];

    public static function fromGstin(?string $gstin): ?string
    {
        $gstin = strtoupper(trim((string) $gstin));

        if (strlen($gstin) !== 15) {
            return null;
        }

        $code = substr($gstin, 0, 2);

        return isset(self::NAMES[$code]) ? $code : null;
    }

    public static function isValid(?string $code): bool
    {
        return $code !== null && isset(self::NAMES[$code]);
    }

    public static function name(?string $code): ?string
    {
        return $code === null ? null : (self::NAMES[$code] ?? null);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::NAMES as $code => $name) {
            $options[] = ['value' => $code, 'label' => "{$code} — {$name}"];
        }

        return $options;
    }

    /**
     * A GSTIN's shape: 2 digits, 10-character PAN, entity digit, Z, check.
     */
    public static function looksLikeGstin(?string $gstin): bool
    {
        return (bool) preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', strtoupper(trim((string) $gstin)));
    }
}
