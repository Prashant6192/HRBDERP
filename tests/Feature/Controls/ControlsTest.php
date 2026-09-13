<?php

declare(strict_types=1);

namespace Tests\Feature\Controls;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Approvals\Models\Approval;
use App\Domain\Approvals\Models\ApprovalAction;
use App\Domain\Approvals\Services\ApprovalSignature;
use App\Domain\Documents\Models\Document;
use App\Domain\Formulation\Models\Formula;
use App\Domain\Formulation\Models\FormulaIngredient;
use App\Domain\Formulation\Models\FormulaVersion;
use App\Domain\Formulation\Services\FormulaSecurityService;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\InventoryTransaction;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Inventory\Services\RecallTraceService;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\Manufacturing\Models\ManufacturingOrderLine;
use App\Domain\MasterData\Models\Product;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Procurement\Models\GoodsReceiptLine;
use App\Domain\Quality\Models\QcInspection;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use App\Notifications\ErpAlert;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ControlsTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private Warehouse $store;

    private Warehouse $quarantine;

    private Uom $kg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UomSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->facility = Facility::factory()->manufacturing()->withStores([WarehouseType::RawMaterial, WarehouseType::Packaging, WarehouseType::Quarantine, WarehouseType::FinishedGoods])->create();
        $this->store = $this->facility->stores()->where('type', WarehouseType::RawMaterial->value)->firstOrFail();
        $this->quarantine = $this->facility->stores()->where('is_quarantine', true)->firstOrFail();
        $this->kg = Uom::query()->where('code', 'KG')->firstOrFail();
    }

    /** The recipe screens sit behind the formula PIN. */
    private function unlockedAs(User $user): static
    {
        app(FormulaSecurityService::class)->setPin($user, '1234');
        $this->actingAs($user)->post(route('formulas.verify'), ['secret' => '1234']);

        return $this;
    }

    private function user(RoleName $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role->value);

        return $user;
    }

    #[Test]
    public function the_author_of_a_recipe_cannot_activate_it_and_the_checker_signs(): void
    {
        Notification::fake();

        // Both may approve recipes; what matters is that they are two people.
        $checker = $this->user(RoleName::Director, ['name' => 'Rekha Director']);
        $author = $this->user(RoleName::Director, ['name' => 'Anil Author', 'approving_authority_id' => $checker->id]);

        $formula = Formula::factory()->create();
        $version = FormulaVersion::factory()->create(['formula_id' => $formula->id, 'status' => 'draft', 'created_by' => $author->id, 'total_percentage' => '100']);
        FormulaIngredient::factory()->create(['formula_version_id' => $version->id, 'item_id' => RawMaterial::factory()->create()->id, 'percentage' => '100', 'line_no' => 1]);

        // The author's activation becomes a request, not an activation.
        $this->unlockedAs($author)->post(route('formulas.versions.activate', [$formula, $version]))->assertRedirect();
        $this->assertSame('draft', $version->fresh()->status->value);
        $approval = Approval::query()->where('workflow_key', 'formula.activate')->firstOrFail();
        $this->assertSame('pending', $approval->status->value);
        Notification::assertSentTo($checker, ErpAlert::class, fn (ErpAlert $n) => $n->category === 'approval');

        // The author cannot sign their own request.
        $this->actingAs($author)->post(route('approvals.approve', $approval))->assertRedirect();
        $this->assertSame('pending', $approval->fresh()->status->value);

        // The approving authority can; the version goes live in their name.
        $this->actingAs($checker)->get(route('approvals.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('mine', 1)->where('mine.0.can_act', true));
        $this->actingAs($checker)->post(route('approvals.approve', $approval), ['comment' => 'Reviewed the change.'])->assertRedirect();

        $this->assertSame('approved', $approval->fresh()->status->value);
        $this->assertSame('active', $version->fresh()->status->value);
        $this->assertSame($checker->id, $version->fresh()->approved_by);

        // The decision is signed and verifies; tampering would not.
        $action = ApprovalAction::query()->firstOrFail();
        $this->assertNotNull($action->signature_hash);
        $this->assertTrue(ApprovalSignature::verify($action, $approval->approvable_type, $approval->approvable_id));
        $forged = clone $action;
        $forged->comment = 'Never reviewed.';
        $this->assertFalse(ApprovalSignature::verify($forged, $approval->approvable_type, $approval->approvable_id));
    }

    #[Test]
    public function a_risky_manufacturing_release_goes_for_a_second_signature(): void
    {
        $director = $this->user(RoleName::Director);
        $manager = $this->user(RoleName::FactoryManager);

        $item = RawMaterial::factory()->create(['stock_uom_id' => $this->kg->id, 'minimum_stock' => '0', 'reorder_level' => '1']);
        app(InventoryLedgerService::class)->receive($item, $this->store, '100', InventoryLot::factory()->forItem($item)->create());

        $formula = Formula::factory()->create();
        $active = FormulaVersion::factory()->active()->create(['formula_id' => $formula->id]);
        $formula->forceFill(['active_version_id' => $active->id])->save();
        // The batch is on a different, superseded version: a non-standard formula.
        $old = FormulaVersion::factory()->create(['formula_id' => $formula->id, 'status' => 'superseded', 'version_number' => 9]);

        $order = ManufacturingOrder::query()->create([
            'number' => 'MO-RISK', 'facility_id' => $this->facility->id, 'formula_id' => $formula->id, 'formula_version_id' => $old->id,
            'planned_quantity' => '10', 'planned_uom_id' => $this->kg->id, 'status' => 'draft', 'created_by' => $director->id,
        ]);
        ManufacturingOrderLine::query()->create([
            'manufacturing_order_id' => $order->id, 'line_no' => 1, 'store_kind' => 'raw_material', 'item_id' => $item->id,
            'uom_id' => $this->kg->id, 'percentage' => '100', 'planned_quantity' => '10',
        ]);

        $this->actingAs($manager)->post(route('manufacturing.approve', $order))->assertRedirect();
        $this->assertSame('draft', $order->fresh()->status->value, 'Not released: a trigger fired.');

        $approval = Approval::query()->where('workflow_key', 'production.release')->firstOrFail();
        $this->assertSame('non_standard_formula', $approval->context['triggers'][0]['key']);

        $this->actingAs($manager)->get(route('manufacturing.show', $order))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('approval.triggers.0.key', 'non_standard_formula'));

        // The director signs; the order is approved and materials reserved.
        $this->actingAs($director)->post(route('approvals.approve', $approval))->assertRedirect();
        $this->assertSame('approved', $order->fresh()->status->value);
        $this->assertSame('10', rtrim(rtrim((string) app(StockBalanceService::class)->reserved($item, $this->store), '0'), '.'));
    }

    #[Test]
    public function the_person_who_booked_a_delivery_cannot_release_it_and_a_rejected_lot_needs_an_override(): void
    {
        $receiver = $this->user(RoleName::FactoryManager);
        $qc = $this->user(RoleName::QcManager);
        $second = $this->user(RoleName::QcManager);
        $qc->forceFill(['formula_pin_hash' => null])->save();
        config(['erp.qc.require_pin' => false]);

        $item = RawMaterial::factory()->create(['stock_uom_id' => $this->kg->id, 'requires_qc' => true]);
        $lot = InventoryLot::factory()->forItem($item)->pendingQc()->create();
        app(InventoryLedgerService::class)->receive($item, $this->quarantine, '10', $lot);

        $receipt = GoodsReceipt::query()->create(['number' => 'GRN-MC', 'warehouse_id' => $this->quarantine->id, 'received_at' => now()->toDateString(), 'status' => 'received', 'posted_at' => now(), 'received_by' => $receiver->id, 'created_by' => $receiver->id]);
        $line = GoodsReceiptLine::query()->create(['goods_receipt_id' => $receipt->id, 'item_id' => $item->id, 'quantity' => '10', 'uom_id' => $this->kg->id, 'stock_quantity' => '10', 'lot_id' => $lot->id]);
        $inspection = QcInspection::query()->create(['number' => 'QCI-MC', 'lot_id' => $lot->id, 'item_id' => $item->id, 'goods_receipt_line_id' => $line->id, 'quantity' => '10', 'status' => LotQcStatus::Pending, 'destination_warehouse_id' => $this->store->id]);

        // Maker-checker: the receiver may not release what they booked in.
        $this->actingAs($receiver)->post(route('qc.approve', $inspection), ['remarks' => 'fine'])
            ->assertSessionHasErrors('decision');
        $this->assertSame('pending', $inspection->fresh()->status->value);

        // QC rejects it; releasing it after all is an override for a second signature.
        $this->actingAs($qc)->post(route('qc.reject', $inspection), ['remarks' => 'off-spec'])->assertRedirect();
        $this->assertSame('rejected', $inspection->fresh()->status->value);

        $this->actingAs($qc)->post(route('qc.approve', $inspection), ['remarks' => 'retested, within spec'])->assertRedirect();
        $this->assertSame('rejected', $inspection->fresh()->status->value, 'Still rejected until someone else signs.');
        $approval = Approval::query()->where('workflow_key', 'qc.override')->firstOrFail();

        // Not the receiver either: a third person signs the override.
        $this->actingAs($receiver)->post(route('approvals.approve', $approval))->assertRedirect();
        $this->assertSame('rejected', $inspection->fresh()->status->value, 'The receiver still cannot release what they booked in.');
        $this->assertSame('pending', $approval->fresh()->status->value);

        $this->actingAs($second)->post(route('approvals.approve', $approval))->assertRedirect();
        $this->assertSame('approved', $inspection->fresh()->status->value);
        $this->assertSame('10', rtrim(rtrim((string) app(StockBalanceService::class)->onHand($item, $this->store), '0'), '.'), 'Released to the store on the override.');
    }

    #[Test]
    public function a_posting_is_corrected_by_a_reversal_and_never_edited(): void
    {
        $keeper = $this->user(RoleName::WarehouseManager);
        $item = RawMaterial::factory()->create(['stock_uom_id' => $this->kg->id]);
        $lot = InventoryLot::factory()->forItem($item)->create();
        $original = app(InventoryLedgerService::class)->receive($item, $this->store, '25', $lot);

        $this->actingAs($keeper)->post(route('ledger.reverse', $original), ['reason' => 'keyed against the wrong batch'])->assertRedirect();

        $reversal = InventoryTransaction::query()->where('reverses_transaction_id', $original->id)->firstOrFail();
        $this->assertSame(InventoryTransactionType::Reversal, $reversal->type);
        $this->assertSame('-25', rtrim(rtrim((string) $reversal->lines()->first()->quantity, '0'), '.'));
        $this->assertSame('0', rtrim(rtrim((string) app(StockBalanceService::class)->onHand($item, $this->store), '0'), '.'));
        $this->assertNotNull($original->fresh(), 'The original stays.');

        // Twice is refused; so is reversing a reversal.
        $this->actingAs($keeper)->post(route('ledger.reverse', $original), ['reason' => 'again by mistake'])->assertSessionHasErrors('reason');
        $this->actingAs($keeper)->post(route('ledger.reverse', $reversal), ['reason' => 'undo the undo'])->assertSessionHasErrors('reason');

        $this->expectException(\RuntimeException::class);
        $original->update(['reason' => 'edited']);
    }

    #[Test]
    public function a_defective_lot_is_traced_to_every_batch_client_and_store_it_reached(): void
    {
        $viewer = $this->user(RoleName::FactoryManager);
        $fg = $this->facility->stores()->where('type', WarehouseType::FinishedGoods->value)->firstOrFail();
        $rm = RawMaterial::factory()->create(['name' => 'Aloe Extract', 'stock_uom_id' => $this->kg->id]);
        $product = Product::factory()->create(['name' => 'Aloe Gel 200ml']);
        $ledger = app(InventoryLedgerService::class);

        $bad = InventoryLot::factory()->forItem($rm)->create(['batch_number' => 'RM-BAD']);
        $ledger->receive($rm, $this->store, '50', $bad);

        $formula = Formula::factory()->create();
        $version = FormulaVersion::factory()->active()->create(['formula_id' => $formula->id]);
        $order = ManufacturingOrder::query()->create(['number' => 'MO-TRACE', 'facility_id' => $this->facility->id, 'formula_id' => $formula->id, 'formula_version_id' => $version->id, 'product_id' => $product->id, 'planned_quantity' => '100', 'planned_uom_id' => $this->kg->id, 'status' => 'completed', 'completed_at' => now()]);
        $ledger->issue($rm, $this->store, '20', $bad, InventoryTransactionType::ProductionConsumption, $order);

        $out = InventoryLot::factory()->forItem($product)->create(['batch_number' => 'FG-OUT']);
        $ledger->receive($product, $fg, '400', $out, InventoryTransactionType::ProductionOutput, $order);
        $order->forceFill(['output_lot_id' => $out->id])->save();
        $ledger->issue($product, $fg, '100', $out, InventoryTransactionType::SalesDispatch);

        $trace = app(RecallTraceService::class)->trace($bad);

        $this->assertSame(1, $trace['summary']['orders']);
        $this->assertSame(1, $trace['summary']['batches']);
        $this->assertSame(1, $trace['summary']['finished_goods']);
        $this->assertSame(1, $trace['summary']['dispatched']);
        $this->assertSame('FG-OUT', $trace['affected'][0]['lot']['batch_number']);
        $this->assertSame('300', $trace['affected'][0]['on_hand']);
        $this->assertSame('100', $trace['affected'][0]['dispatched']['quantity']);
        $this->assertSame('MO-TRACE', $trace['orders'][0]['number']);

        // Backwards from the finished batch: made from the bad lot.
        $back = app(RecallTraceService::class)->trace($out);
        $this->assertSame('RM-BAD', $back['backward'][0]['batch_number']);

        $this->actingAs($viewer)->get(route('lots.trace', $bad))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('lots/trace')->where('trace.summary.batches', 1));
    }

    #[Test]
    public function documents_are_versioned_and_approved_by_someone_other_than_their_author(): void
    {
        Storage::fake('local');
        $author = $this->user(RoleName::QcManager);
        $approver = $this->user(RoleName::FactoryManager);
        $product = Product::factory()->create();

        $this->actingAs($author)->post(route('documents.store'), [
            'kind' => 'specification', 'title' => 'Aloe Gel finished product spec', 'item_id' => $product->id,
            'file' => UploadedFile::fake()->create('spec.pdf', 20, 'application/pdf'),
        ])->assertRedirect();

        $v1 = Document::query()->firstOrFail();
        $this->assertSame('SPEC-0001', $v1->code);
        $this->assertSame(1, $v1->version);
        $this->assertSame('draft', $v1->status->value);

        // The author cannot approve their own draft; another may.
        $this->actingAs($author)->post(route('documents.approve', $v1))->assertRedirect();
        $this->assertSame('draft', $v1->fresh()->status->value);
        $this->actingAs($approver)->post(route('documents.approve', $v1))->assertRedirect();
        $this->assertSame('approved', $v1->fresh()->status->value);

        // A second version supersedes the first on approval; production sees only the current one.
        $this->actingAs($author)->post(route('documents.store'), ['code' => 'SPEC-0001', 'kind' => 'specification', 'title' => 'Aloe Gel finished product spec', 'change_summary' => 'pH range widened'])->assertRedirect();
        $v2 = Document::query()->where('code', 'SPEC-0001')->where('version', 2)->firstOrFail();
        $this->assertSame($v1->id, $v2->supersedes_id);
        $this->actingAs($approver)->post(route('documents.approve', $v2))->assertRedirect();
        $this->assertSame('superseded', $v1->fresh()->status->value);
        $this->assertSame('approved', $v2->fresh()->status->value);

        $formula = Formula::factory()->create();
        $version = FormulaVersion::factory()->active()->create(['formula_id' => $formula->id]);
        $order = ManufacturingOrder::query()->create(['number' => 'MO-DOC', 'facility_id' => $this->facility->id, 'formula_id' => $formula->id, 'formula_version_id' => $version->id, 'product_id' => $product->id, 'planned_quantity' => '100', 'planned_uom_id' => $this->kg->id, 'status' => 'draft']);
        $this->actingAs($approver)->get(route('manufacturing.show', $order))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('documents', 1)->where('documents.0.version', 2));

        $this->actingAs($approver)->get(route('documents.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('documents/index')->has('groups', 1)->where('groups.0.current_version', 2)->has('groups.0.versions', 2));

        $this->actingAs($approver)->get(route('documents.download', $v1))->assertOk();
    }

    #[Test]
    public function an_employee_can_be_given_an_approving_authority(): void
    {
        $admin = $this->user(RoleName::SuperAdmin);
        $boss = $this->user(RoleName::Director, ['name' => 'Boss']);

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'New Hire', 'email' => 'new@hrbd.test', 'password' => 'Password!123', 'password_confirmation' => 'Password!123',
            'status' => 'active', 'approving_authority_id' => $boss->id,
        ])->assertRedirect();

        $hire = User::query()->where('email', 'new@hrbd.test')->firstOrFail();
        $this->assertSame($boss->id, $hire->approving_authority_id);

        $this->actingAs($admin)->get(route('users.show', $hire))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('user.approving_authority.name', 'Boss'));
    }
}
