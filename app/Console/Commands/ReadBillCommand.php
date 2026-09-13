<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Procurement\Contracts\InvoiceReader;
use App\Domain\Procurement\Exceptions\InvoiceIntakeException;
use App\Domain\Procurement\Services\InvoiceIntakeService;
use Illuminate\Console\Command;

/**
 * Try the bill reader on a file from the command line — the way to check a
 * real supplier's bill against the reader without booking anything.
 */
class ReadBillCommand extends Command
{
    protected $signature = 'erp:read-bill {path : The bill, a PDF or a JPEG / PNG / WebP photo} {--json : Print the raw extraction as JSON}';

    protected $description = 'Read a supplier\'s bill with the configured reader and show what it found and how it matched.';

    public function handle(InvoiceReader $reader, InvoiceIntakeService $intake): int
    {
        $path = (string) $this->argument('path');

        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        if (! $reader->available()) {
            $this->error('The bill reader is not set up: ANTHROPIC_API_KEY is missing from the environment.');

            return self::FAILURE;
        }

        $mime = (string) (mime_content_type($path) ?: 'application/octet-stream');

        try {
            $extraction = $reader->read((string) file_get_contents($path), $mime, basename($path));
        } catch (InvoiceIntakeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $intake->sanitise($extraction);

        if ($this->option('json')) {
            $this->line((string) json_encode($extraction->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $vendor = $intake->matchVendor($extraction);
        $lines = $intake->matchLines($extraction);

        $this->info("Read by {$extraction->model}: ".($extraction->documentType ?? 'document type unknown'));
        $this->table(['Field', 'Value'], [
            ['Vendor', $extraction->vendorName ?? '—'],
            ['Vendor GSTIN', $extraction->vendorGstin ?? '—'],
            ['Buyer GSTIN', $extraction->buyerGstin ?? '—'],
            ['Matched vendor', $vendor['id'] ? "{$vendor['name']} (by {$vendor['matched_by']})" : 'not on file'],
            ['Invoice no.', $extraction->invoiceNumber ?? '—'],
            ['Invoice date', $extraction->invoiceDate ?? '—'],
            ['Subtotal / tax / total', ($extraction->subtotal ?? '—').' / '.($extraction->tax ?? '—').' / '.($extraction->total ?? '—').' '.$extraction->currency],
        ]);

        $this->table(['#', 'Description', 'HSN', 'Qty', 'Unit', 'Rate', 'Batch', 'Mfg', 'Exp', 'Matched item', 'Unit id'], array_map(fn (array $l) => [
            $l['index'] + 1, $l['description'], $l['hsn'] ?? '', $l['quantity'] ?? '', $l['unit'] ?? '', $l['rate'] ?? '',
            $l['batch'] ?? '', $l['manufactured_at'] ?? '', $l['expiry_at'] ?? '', $l['item_label'] ?? 'not matched', $l['uom_id'] ?? '',
        ], $lines));

        foreach ($extraction->warnings as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }
}
