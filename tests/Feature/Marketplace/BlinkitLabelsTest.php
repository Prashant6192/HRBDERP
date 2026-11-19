<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Marketplace\Models\BlinkitProduct;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Blinkit Labels: products kept once, SKU labels two to a 100 × 25 mm row
 * and box labels on 4 × 6 inch pages.
 */
class BlinkitLabelsTest extends TestCase
{
    use RefreshDatabase;

    private User $office;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->office = User::factory()->create();
        $this->office->assignRole(RoleName::SuperAdmin->value);

        // The products from the label sheet (the migration adds them on a
        // live database; the test database is shared, so they are made here).
        BlinkitProduct::query()->delete();

        foreach ([
            ['21650766', '768007', 'Rahat rooh Sukoon Pain Relieving Oil 300ml pack of 1', '562', 10],
            ['21650720', '768191', 'Rahat rooh Ayurvedic Hair Oil for Headache & Relaxation Oil 300ml pack of 1', '710', 10],
            ['21650697', '1390557', 'Rahat rooh Ayurved Ratna Cool Hair Oil(Bottle)-300ml', '280', null],
        ] as [$upc, $pid, $name, $mrp, $units]) {
            BlinkitProduct::create(['brand_name' => 'Rahat Rooh', 'product_name' => $name, 'upc' => $upc, 'pid' => $pid, 'mrp' => $mrp, 'uom' => '300 ml', 'units_in_box' => $units]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function product(array $overrides = []): array
    {
        return [
            'brand_name' => 'Rahat Rooh', 'product_name' => 'Rahat rooh Lauki Oil 200ml pack of 1', 'upc' => '21650999',
            'pid' => '700001', 'mrp' => '260', 'uom' => '200 ml', 'units_in_box' => 12,
            ...$overrides,
        ];
    }

    #[Test]
    public function the_screen_lists_each_product_with_its_barcode(): void
    {
        $this->assertSame(['21650697', '21650720', '21650766'], BlinkitProduct::query()->orderBy('upc')->pluck('upc')->all());

        $this->actingAs($this->office)->get(route('blinkit-labels.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('blinkit/labels')
                ->has('products', 3)
                ->where('products', fn ($rows) => collect($rows)->firstWhere('upc', '21650766')['mrp'] === '562'
                    && count(collect($rows)->firstWhere('upc', '21650766')['bars']) > 0
                    && collect($rows)->firstWhere('upc', '21650697')['units_in_box'] === null)
                ->where('can.manage', true));
    }

    #[Test]
    public function a_product_is_added_and_edited_and_a_upc_is_never_used_twice(): void
    {
        $this->actingAs($this->office)->post(route('blinkit-labels.store'), $this->product())->assertSessionHasNoErrors();
        $product = BlinkitProduct::query()->where('upc', '21650999')->sole();
        $this->assertSame([12, '700001'], [$product->units_in_box, $product->pid]);

        $this->actingAs($this->office)->post(route('blinkit-labels.store'), $this->product(['product_name' => 'Another']))->assertSessionHasErrors('upc');
        $this->actingAs($this->office)->post(route('blinkit-labels.store'), $this->product(['upc' => '२१६५']))->assertSessionHasErrors('upc');

        $this->actingAs($this->office)->patch(route('blinkit-labels.update', $product), $this->product(['mrp' => '275', 'units_in_box' => null]))->assertSessionHasNoErrors();
        $this->assertSame(['275.00', null], [(string) $product->fresh()->mrp, $product->fresh()->units_in_box]);
    }

    #[Test]
    public function sku_labels_print_two_to_a_100_by_25_mm_row_and_box_labels_on_4_by_6_inch(): void
    {
        $product = BlinkitProduct::query()->where('upc', '21650766')->sole();

        $sku = $this->actingAs($this->office)->get(route('blinkit-labels.print', [$product, 'type' => 'sku', 'mfg' => '2026-11', 'exp' => '2029-10', 'copies' => 3]));
        $sku->assertOk()->assertHeader('content-type', 'application/pdf');
        $pdf = (string) $sku->getContent();
        $this->assertSame(2, preg_match_all('/\/Type\s*\/Page(?![s\w])/', $pdf), 'Three labels, two rows.');
        $this->assertMatchesRegularExpression('/\/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+283\.46\d*\s+70\.86\d*\s*\]/', $pdf, '100 × 25 mm');

        $box = $this->actingAs($this->office)->get(route('blinkit-labels.print', [$product, 'type' => 'box', 'copies' => 2]));
        $box->assertOk();
        $pdf = (string) $box->getContent();
        $this->assertSame(2, preg_match_all('/\/Type\s*\/Page(?![s\w])/', $pdf));
        $this->assertMatchesRegularExpression('/\/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+288(?:\.0+)?\s+432(?:\.0+)?\s*\]/', $pdf, '4 × 6 inch');

        // Expiry before manufacture, or no count, is not printed.
        $this->actingAs($this->office)->get(route('blinkit-labels.print', [$product, 'type' => 'sku', 'mfg' => '2026-11', 'exp' => '2026-10', 'copies' => 2]))->assertStatus(422);
        $this->actingAs($this->office)->get(route('blinkit-labels.print', [$product, 'type' => 'sku']))->assertSessionHasErrors('copies');
    }

    #[Test]
    public function only_people_with_online_order_rights_see_or_change_them(): void
    {
        $someone = User::factory()->create();

        $this->actingAs($someone)->get(route('blinkit-labels.index'))->assertForbidden();
        $this->actingAs($someone)->post(route('blinkit-labels.store'), $this->product())->assertForbidden();
    }
}
