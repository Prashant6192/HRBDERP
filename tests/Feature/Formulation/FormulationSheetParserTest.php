<?php

declare(strict_types=1);

namespace Tests\Feature\Formulation;

use App\Domain\Formulation\Services\FormulationSheetParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsFormulationWorkbooks;
use Tests\TestCase;

class FormulationSheetParserTest extends TestCase
{
    use BuildsFormulationWorkbooks;

    private FormulationSheetParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new FormulationSheetParser;
    }

    #[Test]
    public function it_reads_the_column_layout(): void
    {
        $formula = $this->parser->parseRows('Sheet1', $this->tabularSheet());

        $this->assertNotNull($formula);
        $this->assertSame('Test Clarifying Face Wash', $formula->name);
        $this->assertSame('tabular', $formula->layout);
        $this->assertCount(4, $formula->ingredients);

        [$a, $b, $c, $d] = $formula->ingredients;

        $this->assertSame('Test Surfactant A', $a->name);
        $this->assertSame('SURF-A', $a->tradeName);
        $this->assertSame('15', $a->percentage);

        $this->assertNull($b->tradeName, 'A trade name equal to the INCI name is not repeated');
        $this->assertSame('5.5', $b->percentage);

        $this->assertNull($c->percentage);
        $this->assertTrue($c->isAsRequired());
        $this->assertNotEmpty($c->warnings);

        $this->assertSame('0.001', $d->percentage);

        $this->assertSame('20.501', $formula->totalPercentage()->__toString());
        $this->assertFalse($formula->hasQs());
        $this->assertStringContainsString('79.499% is unaccounted for', implode(' ', $formula->warnings));
    }

    #[Test]
    public function it_reads_the_vertical_layout_with_wrapped_cells_and_noise(): void
    {
        $formula = $this->parser->parseRows('Sheet2', $this->verticalSheet());

        $this->assertNotNull($formula);
        $this->assertSame('Test Strengthening Shampoo', $formula->name, 'A word broken across cells is mended');
        $this->assertSame('vertical', $formula->layout);
        $this->assertSame('100', $formula->batchSize);
        $this->assertSame('ML', $formula->batchUomCode);

        $names = array_map(static fn ($i) => $i->name, $formula->ingredients);
        $this->assertSame([
            'Purified Water',
            'Test Surfactant A',
            'Test Extract (Long Name) Leaf Extract',
            'Test Thickener E',
        ], $names);

        [$water, $surfactant, $extract, $thickener] = $formula->ingredients;

        $this->assertTrue($water->isQs);
        $this->assertSame('IP', $water->grade);
        $this->assertSame('Solvent', $water->purpose);

        $this->assertSame('12', $surfactant->percentage);
        $this->assertSame('IH', $surfactant->grade);
        $this->assertSame('Cleaning', $surfactant->purpose);

        $this->assertSame('Skin conditioning', $extract->purpose, 'A function wrapped over two cells is rejoined with a space');
        $this->assertSame('2', $extract->percentage);

        $this->assertNull($thickener->grade, 'A missing grade does not swallow the amount');
        $this->assertSame('0.5', $thickener->percentage);
        $this->assertSame('Gelling', $thickener->purpose);

        $joined = implode(' ', $formula->warnings);
        $this->assertStringContainsString('Ignored "Moon"', $joined);
        $this->assertStringContainsString('Ignored "Enterprises"', $joined);
        $this->assertTrue($formula->hasQs());
    }

    #[Test]
    public function it_reads_a_whole_workbook_and_skips_empty_sheets(): void
    {
        $path = $this->writeWorkbook([
            'Wash' => $this->tabularSheet(),
            'Empty' => [[null, null]],
            'Shampoo' => $this->verticalSheet(),
        ]);

        try {
            $workbook = $this->parser->parseFile($path);
        } finally {
            @unlink($path);
        }

        $this->assertCount(2, $workbook->formulas);
        $this->assertSame(['Empty'], $workbook->skippedSheets);
        $this->assertSame('Wash', $workbook->formulas[0]->sheet);
        $this->assertSame('Shampoo', $workbook->formulas[1]->sheet);
    }

    #[Test]
    public function a_control_character_in_a_name_is_read_as_a_hyphen(): void
    {
        $formula = $this->parser->parseRows('Sheet', [
            ['Test Product'],
            [null, "Test Base (and) TEA\x02"],
            [null, 'Dodecylbenzenesulfonate'],
            [null, 'IH'],
            [null, 4.2],
            [null, 'Conditioning'],
        ]);

        $this->assertSame('Test Base (and) TEA-Dodecylbenzenesulfonate', $formula->ingredients[0]->name);
    }
}
