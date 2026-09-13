<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Procurement\Contracts\InvoiceReader;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeInvoiceReader;
use Tests\TestCase;

class ReadBillCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reads_a_bill_from_the_shell_and_shows_what_it_found(): void
    {
        $this->seed(UomSeeder::class);
        $this->app->instance(InvoiceReader::class, new FakeInvoiceReader([
            'document_type' => 'tax_invoice', 'vendor_name' => 'Fragrance Specialities', 'vendor_gstin' => '09AAECR0368M2ZY',
            'invoice_number' => 'FS/25-26/2131', 'invoice_date' => '28-Feb-26', 'total' => '15,104.00',
            'lines' => [['description' => 'Dazzle', 'hsn' => '33029012', 'quantity' => '1.000', 'unit' => 'kg.', 'rate' => '1,700.00', 'batch' => 'DZ-02702/25-26']],
        ]));

        $this->artisan('erp:read-bill', ['path' => base_path('tests/Fixtures/bills/fragrance-specialities-fs-2131.pdf')])
            ->expectsOutputToContain('FS/25-26/2131')
            ->expectsOutputToContain('2026-02-28')
            ->expectsOutputToContain('not on file')
            ->assertSuccessful();

        $this->artisan('erp:read-bill', ['path' => base_path('tests/Fixtures/bills/fragrance-specialities-fs-2131.pdf'), '--json' => true])
            ->expectsOutputToContain('"document_type": "tax_invoice"')
            ->assertSuccessful();

        $this->app->instance(InvoiceReader::class, new FakeInvoiceReader(available: false));
        $this->artisan('erp:read-bill', ['path' => base_path('tests/Fixtures/bills/caldic-proforma-so-356105.pdf')])
            ->expectsOutputToContain('ANTHROPIC_API_KEY')
            ->assertFailed();
    }
}
