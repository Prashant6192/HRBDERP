<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Services;

use App\Domain\MasterData\Models\Item;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Procurement\Contracts\InvoiceReader;
use App\Domain\Procurement\DTOs\InvoiceExtraction;
use App\Domain\Procurement\Exceptions\InvoiceIntakeException;
use App\Domain\Procurement\Models\Vendor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A bill comes in, is kept, is read, and is matched to the vendor and the
 * items the ERP already knows. The result waits (for a couple of hours)
 * under a token until the person books the receipt from it.
 */
class InvoiceIntakeService
{
    public const string DISK = 'local';

    public const int TTL_MINUTES = 180;

    public function __construct(private readonly InvoiceReader $reader) {}

    /**
     * @return array<string, mixed> the intake payload the receipt screen shows
     */
    public function intake(UploadedFile $file, int $userId): array
    {
        $mime = (string) $file->getMimeType();

        if (! in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], strict: true)) {
            throw new InvoiceIntakeException('Upload the bill as a PDF or a JPEG / PNG / WebP photo.');
        }

        $token = (string) Str::uuid();
        $path = 'goods-receipts/intake/'.now()->format('Y/m').'/'.$token.'.'.($file->guessExtension() ?: 'bin');
        Storage::disk(self::DISK)->put($path, (string) file_get_contents($file->getRealPath()));

        try {
            $extraction = $this->reader->read((string) file_get_contents($file->getRealPath()), $mime, (string) $file->getClientOriginalName());
        } catch (InvoiceIntakeException $e) {
            // Nothing was booked from it, so nothing is kept.
            Storage::disk(self::DISK)->delete($path);

            throw $e;
        }

        $this->sanitise($extraction);

        $payload = [
            'token' => $token,
            'path' => $path,
            'name' => (string) $file->getClientOriginalName(),
            'mime' => $mime,
            'uploaded_by' => $userId,
            'extraction' => $extraction->toArray(),
            'vendor' => $this->matchVendor($extraction),
            'lines' => $this->matchLines($extraction),
        ];

        Cache::put($this->key($token), $payload, now()->addMinutes(self::TTL_MINUTES));

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(?string $token): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }

        $payload = Cache::get($this->key($token));

        return is_array($payload) ? $payload : null;
    }

    public function forget(string $token): void
    {
        Cache::forget($this->key($token));
    }

    /**
     * What the reader returned is checked against what we know before it
     * is trusted: our own GSTIN printed under "Buyer" is never the
     * vendor's, and a proforma is not a delivery.
     */
    public function sanitise(InvoiceExtraction $extraction): void
    {
        $ours = strtoupper(trim((string) config('erp.company.gstin')));

        if ($extraction->vendorGstin !== null && ($extraction->vendorGstin === $ours || $extraction->vendorGstin === $extraction->buyerGstin)) {
            $extraction->warnings[] = "The GSTIN read as the vendor's ({$extraction->vendorGstin}) is the buyer's — ours. The vendor was matched by name instead; check it.";
            $extraction->vendorGstin = null;
        }

        if ($extraction->isProvisional()) {
            $label = $extraction->documentType === 'quotation' ? 'a quotation' : 'a proforma invoice';
            $extraction->warnings[] = "This is {$label}, not a tax invoice: it says what will be supplied. Book the receipt from the tax invoice or delivery challan that comes with the goods, or check the quantities against what actually arrived.";
        }

        foreach ($extraction->lines as $line) {
            if ($line['quantity'] === null && $line['amount'] !== null && $line['rate'] === null) {
                $extraction->warnings[] = '"'.$line['description'].'" carries an amount but no quantity; it is shown but will not become stock.';
            }
        }

        $extraction->warnings = array_values(array_unique($extraction->warnings));
    }

    /**
     * The vendor the bill came from: by GSTIN first, then by name.
     *
     * @return array{id: int|null, name: string|null, gstin: string|null, matched_by: string|null, suggested: array<string, string|null>}
     */
    public function matchVendor(InvoiceExtraction $extraction): array
    {
        $vendor = null;
        $by = null;

        if ($extraction->vendorGstin !== null) {
            $vendor = Vendor::query()->where('gstin', $extraction->vendorGstin)->first();
            $by = $vendor ? 'gstin' : null;
        }

        if ($vendor === null && $extraction->vendorName !== null) {
            $name = $this->squash($extraction->vendorName);
            $vendor = Vendor::query()->get(['id', 'name', 'legal_name', 'gstin'])
                ->first(fn (Vendor $v) => $this->squash($v->name) === $name || ($v->legal_name && $this->squash($v->legal_name) === $name))
                ?? Vendor::query()->where('name', 'ilike', '%'.$this->firstWords($extraction->vendorName).'%')->first();
            $by = $vendor ? 'name' : null;
        }

        return [
            'id' => $vendor?->id,
            'name' => $vendor?->name,
            'gstin' => $vendor?->gstin,
            'matched_by' => $by,
            'suggested' => [
                'name' => $extraction->vendorName,
                'gstin' => $extraction->vendorGstin,
                'address' => $extraction->vendorAddress,
                'phone' => $extraction->vendorPhone,
                'email' => $extraction->vendorEmail,
            ],
        ];
    }

    /**
     * Each bill line set against the item master: by code printed in the
     * description, then by HSN plus a word of the name, then by name.
     *
     * @return list<array<string, mixed>>
     */
    public function matchLines(InvoiceExtraction $extraction): array
    {
        $items = Item::query()->active()->with('stockUom:id,code')->get(['id', 'code', 'name', 'inci_name', 'type', 'hsn_code', 'stock_uom_id']);
        $uoms = Uom::query()->active()->get(['id', 'code', 'name'])->keyBy(fn (Uom $u) => strtoupper($u->code));
        $matched = [];

        foreach ($extraction->lines as $index => $line) {
            $item = $this->matchItem($line, $items);
            $uom = $this->matchUom($line['unit'], $uoms) ?? ($item?->stockUom ? $uoms->get(strtoupper($item->stockUom->code)) : null);

            $matched[] = [
                'index' => $index,
                'description' => $line['description'],
                'hsn' => $line['hsn'],
                'quantity' => $line['quantity'],
                'unit' => $line['unit'],
                'rate' => $line['rate'],
                'amount' => $line['amount'],
                'batch' => $line['batch'],
                'manufactured_at' => $line['manufactured_at'],
                'expiry_at' => $line['expiry_at'],
                'item_id' => $item?->id,
                'item_label' => $item ? "{$item->code} — {$item->name}" : null,
                'uom_id' => $uom?->id,
            ];
        }

        return $matched;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Item>  $items
     */
    private function matchItem(array $line, $items): ?Item
    {
        $description = strtoupper($line['description']);

        foreach ($items as $item) {
            if ($item->code !== '' && str_contains($description, strtoupper($item->code))) {
                return $item;
            }
        }

        $words = $this->words($line['description']);

        if ($line['hsn'] !== null) {
            $sameHsn = $items->filter(fn (Item $i) => $i->hsn_code !== null && preg_replace('/\D/', '', $i->hsn_code) === preg_replace('/\D/', '', (string) $line['hsn']));
            $best = $this->bestByWords($sameHsn, $words);

            if ($best !== null) {
                return $best;
            }
        }

        return $this->bestByWords($items, $words, minimum: 2);
    }

    /**
     * @param  Collection<int, Item>  $candidates
     * @param  list<string>  $words
     */
    private function bestByWords($candidates, array $words, int $minimum = 1): ?Item
    {
        if ($words === []) {
            return null;
        }

        $best = null;
        $bestScore = 0;

        foreach ($candidates as $item) {
            // The bill may call it by its INCI name rather than ours.
            $itemWords = array_values(array_unique([...$this->words($item->name), ...$this->words((string) ($item->inci_name ?? ''))]));
            $score = count(array_intersect($words, $itemWords));

            if ($score > $bestScore) {
                $best = $item;
                $bestScore = $score;
            }
        }

        return $bestScore >= min($minimum, count($words)) ? $best : null;
    }

    /**
     * @param  Collection<string, Uom>  $uoms
     */
    private function matchUom(?string $unit, $uoms): ?Uom
    {
        if ($unit === null) {
            return null;
        }

        $code = strtoupper(trim($unit, " \t\n\r\0\x0B."));
        $aliases = ['KGS' => 'KG', 'KILOGRAM' => 'KG', 'KILOGRAMS' => 'KG', 'LTR' => 'L', 'LTRS' => 'L', 'LITRE' => 'L', 'LITRES' => 'L', 'LITER' => 'L', 'NOS' => 'PCS', 'NO' => 'PCS', 'PC' => 'PCS', 'PIECES' => 'PCS', 'PIECE' => 'PCS', 'UNITS' => 'PCS', 'UNIT' => 'PCS', 'GM' => 'G', 'GMS' => 'G', 'GRAM' => 'G', 'GRAMS' => 'G', 'MLTR' => 'ML'];
        $code = $aliases[$code] ?? $code;

        return $uoms->get($code);
    }

    /**
     * @return list<string>
     */
    private function words(string $text): array
    {
        $stop = ['THE', 'AND', 'OF', 'FOR', 'WITH', 'IN', 'PER', 'GRADE', 'PACK', 'PKT', 'BAG', 'DRUM', 'BOX', 'NOS', 'PCS', 'KG', 'KGS', 'LTR', 'ML', 'GM'];
        $tokens = preg_split('/[^A-Z0-9]+/', strtoupper($text)) ?: [];

        return array_values(array_unique(array_filter($tokens, fn ($t) => strlen($t) >= 3 && ! in_array($t, $stop, strict: true) && ! is_numeric($t))));
    }

    private function squash(string $text): string
    {
        $text = strtoupper($text);
        $text = preg_replace('/\b(PVT|PRIVATE|LTD|LIMITED|LLP|INC|CO|COMPANY|ENTERPRISES?|INDUSTRIES|TRADERS|AND|&)\b/', '', $text) ?? $text;

        return preg_replace('/[^A-Z0-9]/', '', $text) ?? $text;
    }

    private function firstWords(string $name): string
    {
        $words = array_slice(array_values(array_filter(preg_split('/\s+/', $name) ?: [], fn ($w) => strlen($w) >= 3)), 0, 2);

        return implode(' ', $words) ?: $name;
    }

    private function key(string $token): string
    {
        return "grn-intake:{$token}";
    }
}
