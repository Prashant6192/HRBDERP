<?php

declare(strict_types=1);

namespace Tests\Feature\Formulation;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Formulation\DTOs\ImportOptions;
use App\Domain\Formulation\Models\Formula;
use App\Domain\Formulation\Services\FormulaImportService;
use App\Domain\Formulation\Services\FormulaSecurityService;
use App\Domain\Formulation\Services\FormulaService;
use App\Domain\Formulation\Services\FormulationSheetParser;
use App\Domain\MasterData\Models\Product;
use App\Domain\MasterData\Models\RawMaterial;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsFormulationWorkbooks;
use Tests\TestCase;

class FormulaImportTest extends TestCase
{
    use BuildsFormulationWorkbooks, RefreshDatabase;

    private User $factoryManager;

    private string $workbook;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->factoryManager = User::factory()->create();
        $this->factoryManager->assignRole(RoleName::FactoryManager->value);

        $this->workbook = $this->writeWorkbook([
            'Wash' => $this->tabularSheet(),
            'Shampoo' => $this->verticalSheet(),
            'Shampoo again' => $this->verticalSheet(),
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->workbook);

        parent::tearDown();
    }

    #[Test]
    public function the_plan_says_what_would_happen_without_writing_anything(): void
    {
        RawMaterial::factory()->create(['name' => 'Test Surfactant A', 'code' => 'RM-0500']);
        Product::factory()->create(['name' => 'Test Strengthening Shampoo']);

        $parsed = app(FormulationSheetParser::class)->parseFile($this->workbook);
        $plan = app(FormulaImportService::class)->plan($parsed, new ImportOptions);

        $this->assertSame(['create', 'create', 'skip_duplicate'], array_column($plan->formulas, 'action'));
        $this->assertSame(0, Formula::count());

        $wash = $plan->formulas[0];
        $this->assertSame('Purified Water', $wash['lines'][0]['name'], 'Water is assumed for a sheet with no filler');
        $this->assertTrue($wash['lines'][0]['is_qs']);
        $this->assertSame('match', $wash['lines'][1]['action']);
        $this->assertSame('RM-0500', $wash['lines'][1]['item_code']);
        $this->assertSame('create', $wash['lines'][2]['action']);

        $shampoo = $plan->formulas[1];
        $this->assertSame('Test Strengthening Shampoo', $shampoo['product_name']);
        $this->assertSame('ML', $shampoo['batch_uom']);

        // Water, Humectant B, Alkali C, Colour D, the long extract, Thickener E.
        $this->assertSame(6, $plan->materialsToCreate());
    }

    #[Test]
    public function importing_creates_formulas_and_the_materials_they_need(): void
    {
        $parsed = app(FormulationSheetParser::class)->parseFile($this->workbook);
        $result = app(FormulaImportService::class)->import($parsed, new ImportOptions, $this->factoryManager->id);

        $this->assertCount(2, $result->created);
        $this->assertCount(1, $result->skipped);
        $this->assertCount(7, $result->materialsCreated);

        $wash = Formula::where('name', 'Test Clarifying Face Wash')->firstOrFail();
        $version = $wash->versions()->first();

        $this->assertSame('import', $version->source);
        $this->assertSame('Wash', $version->source_reference);
        $this->assertSame('draft', $version->status->value);
        $this->assertTrue($version->hasQsLine());
        $this->assertSame('20.501000', $version->total_percentage);

        $surfactant = RawMaterial::where('name', 'Test Surfactant A')->firstOrFail();
        $this->assertSame('SURF-A', $surfactant->brand, 'The trade name is kept on the material');
        $this->assertSame('Test Surfactant A', $surfactant->inci_name);
        $this->assertMatchesRegularExpression('/^RM-\d{4}$/', $surfactant->code);

        $water = RawMaterial::where('name', 'Purified Water')->firstOrFail();
        $this->assertSame('1.000000', $water->density_g_per_ml);
        $this->assertFalse($water->requires_qc);

        $shampoo = Formula::where('name', 'Test Strengthening Shampoo')->firstOrFail();
        $this->assertSame($surfactant->id, $shampoo->versions()->first()->ingredients()->skip(1)->first()->item_id, 'The same material is shared between recipes');
    }

    #[Test]
    public function importing_the_same_workbook_again_changes_nothing(): void
    {
        $parsed = app(FormulationSheetParser::class)->parseFile($this->workbook);
        $importer = app(FormulaImportService::class);

        $importer->import($parsed, new ImportOptions, $this->factoryManager->id);
        $again = $importer->import($parsed, new ImportOptions, $this->factoryManager->id);

        $this->assertCount(0, $again->created);
        $this->assertCount(3, $again->skipped);
        $this->assertSame(2, Formula::count());
        $this->assertSame(7, RawMaterial::count());
    }

    #[Test]
    public function a_changed_recipe_becomes_the_next_version(): void
    {
        $parsed = app(FormulationSheetParser::class)->parseFile($this->workbook);
        $importer = app(FormulaImportService::class);
        $importer->import($parsed, new ImportOptions, $this->factoryManager->id);

        // Activate what was imported so the next import can open a new draft.
        $formula = Formula::where('name', 'Test Strengthening Shampoo')->firstOrFail();
        $director = User::factory()->create();
        $director->assignRole(RoleName::Director->value);
        app(FormulaService::class)->activate($formula->versions()->first(), $director->id);

        $rows = $this->verticalSheet();
        $rows[11][1] = 14.0; // surfactant 12 -> 14
        $changed = $this->writeWorkbook(['Shampoo' => $rows]);

        try {
            $result = $importer->import(app(FormulationSheetParser::class)->parseFile($changed), new ImportOptions, $this->factoryManager->id);
        } finally {
            @unlink($changed);
        }

        $this->assertCount(1, $result->versions);
        $this->assertSame(2, $result->versions[0]['version']);
        $this->assertSame(2, $formula->versions()->count());
        $this->assertSame('14.000000', $formula->fresh()->draftVersion()->ingredients()->skip(1)->first()->percentage);
    }

    #[Test]
    public function the_console_command_previews_and_imports(): void
    {
        $this->artisan('erp:import-formulations', ['path' => $this->workbook, '--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(0, Formula::count());

        $this->artisan('erp:import-formulations', ['path' => $this->workbook, '--user' => $this->factoryManager->email])
            ->expectsOutputToContain('2 formula(s) created')
            ->assertSuccessful();

        $this->assertSame(2, Formula::count());
    }

    #[Test]
    public function the_import_screen_previews_then_commits(): void
    {
        Storage::fake('local');

        app(FormulaSecurityService::class)->setPin($this->factoryManager, '1234');
        $this->actingAs($this->factoryManager)->post(route('formulas.verify'), ['secret' => '1234']);

        $upload = new UploadedFile($this->workbook, 'Rozz_formulations.xlsx', null, null, true);

        $this->actingAs($this->factoryManager)
            ->post(route('formulas.imports.preview'), ['workbook' => $upload, 'assume_water_qs' => '1'])
            ->assertRedirect(route('formulas.imports.create'));

        $this->assertSame(0, Formula::count(), 'A preview writes nothing');
        $this->assertCount(1, Storage::disk('local')->files('formula-imports'));

        $pending = session('formula_import');
        $this->assertSame('Rozz_formulations.xlsx', $pending['file_name']);
        $this->assertSame(2, $pending['plan']['summary']['create']);

        $this->actingAs($this->factoryManager)
            ->post(route('formulas.imports.store'), ['token' => $pending['token'], 'assume_water_qs' => '1'])
            ->assertRedirect(route('formulas.index'));

        $this->assertSame(2, Formula::count());
        $this->assertCount(0, Storage::disk('local')->files('formula-imports'), 'The workbook is not kept');
        $this->assertNull(session('formula_import'));
    }

    #[Test]
    public function a_locked_user_cannot_reach_the_import_screen(): void
    {
        app(FormulaSecurityService::class)->setPin($this->factoryManager, '1234');

        $this->actingAs($this->factoryManager)
            ->get(route('formulas.imports.create'))
            ->assertRedirect(route('formulas.unlock'));
    }

    #[Test]
    public function a_production_manager_may_not_import(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RoleName::ProductionManager->value);
        app(FormulaSecurityService::class)->setPin($user, '1234');
        $this->actingAs($user)->post(route('formulas.verify'), ['secret' => '1234']);

        $this->actingAs($user)->get(route('formulas.imports.create'))->assertForbidden();
    }
}
