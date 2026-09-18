<?php

declare(strict_types=1);

namespace App\Domain\Dispatch\Services;

use App\Domain\Dispatch\Models\Dispatch;
use App\Domain\Dispatch\Models\DispatchLine;
use App\Domain\Dispatch\Support\GstStateCodes;
use App\Domain\Warehousing\Models\Facility;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;

/**
 * The GST e-invoice, as the Invoice Registration Portal wants it.
 *
 * The ERP does not sign invoices: the IRP does, and hands back an IRN, an
 * acknowledgement and a signed QR. What the ERP does is prepare the
 * consignment exactly to the e-invoice schema (INV-01, version 1.1) so the
 * JSON can be uploaded to the IRP — through the bulk tool or a GSP — and
 * the IRN it returns recorded here before the goods leave.
 *
 * Money is to the paisa, dates are dd/mm/yyyy, units are the GST unit
 * quantity codes; nothing is estimated.
 */
class EInvoiceService
{
    public const string SCHEMA_VERSION = '1.1';

    /**
     * ERP unit codes → GST unit quantity codes (UQC).
     *
     * @var array<string, string>
     */
    private const array UQC = [
        'PCS' => 'NOS', 'NOS' => 'NOS', 'PC' => 'NOS', 'EA' => 'NOS', 'UNIT' => 'UNT',
        'KG' => 'KGS', 'KGS' => 'KGS', 'G' => 'GMS', 'GM' => 'GMS', 'GMS' => 'GMS', 'MG' => 'MGS',
        'L' => 'LTR', 'LTR' => 'LTR', 'ML' => 'MLT', 'MLT' => 'MLT',
        'BOX' => 'BOX', 'CTN' => 'CTN', 'CARTON' => 'CTN', 'DOZ' => 'DOZ', 'PAC' => 'PAC', 'PACK' => 'PAC',
        'SET' => 'SET', 'BTL' => 'BTL', 'BOTTLE' => 'BTL', 'JAR' => 'NOS', 'TUBE' => 'TUB', 'TUB' => 'TUB',
        'BAG' => 'BAG', 'DRUM' => 'DRM', 'DRM' => 'DRM', 'CAN' => 'CAN', 'ROLL' => 'ROL',
    ];

    public static function uqc(?string $code): string
    {
        return self::UQC[strtoupper(trim((string) $code))] ?? 'OTH';
    }

    /**
     * An IRN is a 64-character hash from the IRP.
     */
    public static function looksLikeIrn(?string $irn): bool
    {
        return (bool) preg_match('/^[0-9a-f]{64}$/i', trim((string) $irn));
    }

    /**
     * The seller on the invoice: the legal entity that bills for goods
     * leaving this facility, falling back to the company's own details.
     *
     * @return array{gstin: string|null, legal_name: string, trade_name: string, address_1: string|null, address_2: string|null, city: string|null, pincode: string|null, state_code: string|null, phone: string|null, email: string|null}
     */
    public function seller(Facility $facility): array
    {
        $gstin = $facility->gstin ?: config('erp.company.gstin');

        return [
            'gstin' => $gstin ? strtoupper((string) $gstin) : null,
            'legal_name' => $facility->legal_name ?: (string) config('erp.company.legal_name', config('erp.company.name')),
            'trade_name' => (string) config('erp.company.name'),
            'address_1' => $facility->address_line_1,
            'address_2' => $facility->address_line_2,
            'city' => $facility->city,
            'pincode' => $facility->pincode,
            'state_code' => GstStateCodes::fromGstin($gstin),
            'phone' => $facility->phone,
            'email' => $facility->email,
        ];
    }

    /**
     * The whole document, schema-shaped, ready for the IRP.
     *
     * @return array<string, mixed>
     */
    public function payload(Dispatch $dispatch): array
    {
        $dispatch->loadMissing(['facility', 'customer', 'lines.item.stockUom', 'lines.lot', 'lines.uom']);

        $seller = $this->seller($dispatch->facility);
        $customer = $dispatch->customer;
        $registered = $customer->isRegistered();
        $pos = $dispatch->place_of_supply ?? $customer->stateCode() ?? $seller['state_code'];

        $items = [];

        foreach ($dispatch->lines as $line) {
            /** @var DispatchLine $line */
            $items[] = $this->item($line, $dispatch->is_interstate);
        }

        $payload = [
            'Version' => self::SCHEMA_VERSION,
            'TranDtls' => [
                'TaxSch' => 'GST',
                'SupTyp' => $registered ? 'B2B' : 'B2C',
                'RegRev' => 'N',
                'IgstOnIntra' => 'N',
            ],
            'DocDtls' => [
                'Typ' => 'INV',
                'No' => (string) $dispatch->invoice_number,
                'Dt' => $this->date($dispatch->invoice_date),
            ],
            'SellerDtls' => array_filter([
                'Gstin' => $seller['gstin'],
                'LglNm' => $seller['legal_name'],
                'TrdNm' => $seller['trade_name'],
                'Addr1' => $seller['address_1'] ?: '-',
                'Addr2' => $seller['address_2'],
                'Loc' => $seller['city'] ?: '-',
                'Pin' => $this->pin($seller['pincode']),
                'Stcd' => $seller['state_code'],
                'Ph' => $seller['phone'],
                'Em' => $seller['email'],
            ], fn ($v) => $v !== null && $v !== ''),
            'BuyerDtls' => array_filter([
                'Gstin' => $registered ? strtoupper((string) $customer->gstin) : 'URP',
                'LglNm' => $customer->legal_name ?: $customer->name,
                'TrdNm' => $customer->name,
                'Pos' => $pos,
                'Addr1' => $customer->billing_address_line_1 ?: '-',
                'Addr2' => $customer->billing_address_line_2,
                'Loc' => $customer->billing_city ?: '-',
                'Pin' => $this->pin($customer->billing_pincode),
                'Stcd' => $registered ? $customer->stateCode() : $pos,
                'Ph' => $customer->phone,
                'Em' => $customer->email,
            ], fn ($v) => $v !== null && $v !== ''),
            'ItemList' => $items,
            'ValDtls' => [
                'AssVal' => $this->money($dispatch->taxable_value),
                'CgstVal' => $this->money($dispatch->cgst),
                'SgstVal' => $this->money($dispatch->sgst),
                'IgstVal' => $this->money($dispatch->igst),
                'CesVal' => 0,
                'StCesVal' => 0,
                'Discount' => 0,
                'OthChrg' => $this->money($dispatch->other_charges),
                'RndOffAmt' => $this->money($dispatch->round_off),
                'TotInvVal' => $this->money($dispatch->total_value),
            ],
        ];

        // A consignee who is not the buyer: the goods go somewhere else.
        if ($dispatch->ship_to_name !== null && $dispatch->ship_to_name !== '') {
            $payload['ShipDtls'] = array_filter([
                'Gstin' => $dispatch->ship_to_gstin ? strtoupper($dispatch->ship_to_gstin) : null,
                'LglNm' => $dispatch->ship_to_name,
                'TrdNm' => $dispatch->ship_to_name,
                'Addr1' => $dispatch->ship_to_address_line_1 ?: '-',
                'Addr2' => $dispatch->ship_to_address_line_2,
                'Loc' => $dispatch->ship_to_city ?: '-',
                'Pin' => $this->pin($dispatch->ship_to_pincode),
                'Stcd' => GstStateCodes::fromGstin($dispatch->ship_to_gstin) ?? $pos,
            ], fn ($v) => $v !== null && $v !== '');
        }

        // Transport, when it is known before the IRN is asked for: the IRP
        // can raise the e-way bill in the same call.
        if ($dispatch->vehicle_number || $dispatch->transporter_gstin || $dispatch->lr_number) {
            $payload['EwbDtls'] = array_filter([
                'TransId' => $dispatch->transporter_gstin ? strtoupper($dispatch->transporter_gstin) : null,
                'TransName' => $dispatch->transporter_name,
                'Distance' => $dispatch->distance_km ?? 0,
                'TransDocNo' => $dispatch->lr_number,
                'TransDocDt' => $this->date($dispatch->lr_date),
                'VehNo' => $dispatch->vehicle_number ? strtoupper(str_replace([' ', '-'], '', $dispatch->vehicle_number)) : null,
                'VehType' => 'R',
                'TransMode' => '1',
            ], fn ($v) => $v !== null && $v !== '');
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function item(DispatchLine $line, bool $interstate): array
    {
        $qty = BigDecimal::of($line->quantity);
        $gross = $qty->multipliedBy(BigDecimal::of($line->unit_price))->toScale(2, RoundingMode::HalfUp);
        $discount = $gross->minus(BigDecimal::of($line->taxable_value))->toScale(2, RoundingMode::HalfUp);

        $item = [
            'SlNo' => (string) $line->line_no,
            'PrdDesc' => $line->description ?: $line->item->name,
            'IsServc' => 'N',
            'HsnCd' => (string) ($line->hsn_code ?? $line->item->hsn_code ?? ''),
            'Qty' => $this->number($qty),
            'Unit' => self::uqc($line->uom?->code ?? $line->item->stockUom?->code),
            'UnitPrice' => $this->money($line->unit_price),
            'TotAmt' => $this->money($gross),
            'Discount' => $this->money($discount->isNegative() ? '0' : $discount),
            'AssAmt' => $this->money($line->taxable_value),
            'GstRt' => $this->number(BigDecimal::of($line->gst_rate)),
            'IgstAmt' => $this->money($interstate ? $line->igst : '0'),
            'CgstAmt' => $this->money($interstate ? '0' : $line->cgst),
            'SgstAmt' => $this->money($interstate ? '0' : $line->sgst),
            'CesRt' => 0,
            'CesAmt' => 0,
            'TotItemVal' => $this->money($line->line_total),
        ];

        if ($line->lot !== null) {
            $item['BchDtls'] = array_filter([
                'Nm' => $line->lot->batch_number,
                'ExpDt' => $this->date($line->lot->expiry_at),
            ], fn ($v) => $v !== null && $v !== '');
        }

        return $item;
    }

    private function date(?CarbonInterface $date): ?string
    {
        return $date?->format('d/m/Y');
    }

    private function pin(?string $pincode): ?int
    {
        $digits = preg_replace('/\D/', '', (string) $pincode);

        return $digits === '' || $digits === null ? null : (int) $digits;
    }

    private function money(BigDecimal|string|int|null $value): float
    {
        return BigDecimal::of($value ?? '0')->toScale(2, RoundingMode::HalfUp)->toFloat();
    }

    private function number(BigDecimal $value): float
    {
        return $value->toScale(3, RoundingMode::HalfUp)->toFloat();
    }
}
