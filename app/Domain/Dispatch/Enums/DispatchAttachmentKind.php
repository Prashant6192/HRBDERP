<?php

declare(strict_types=1);

namespace App\Domain\Dispatch\Enums;

/**
 * The papers that travel with a consignment.
 */
enum DispatchAttachmentKind: string
{
    case Invoice = 'invoice';
    case SignedInvoice = 'signed_invoice';
    case EwayBill = 'eway_bill';
    case Lr = 'lr';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Tax invoice',
            self::SignedInvoice => 'Signed e-invoice (with IRN and QR)',
            self::EwayBill => 'E-way bill',
            self::Lr => 'Lorry receipt / consignment note',
            self::Other => 'Other',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $k) => ['value' => $k->value, 'label' => $k->label()], self::cases());
    }
}
