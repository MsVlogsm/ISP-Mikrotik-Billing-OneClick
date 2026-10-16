<?php

namespace Tests\Feature;

use App\Models\StockInventorySupplier;
use App\Models\StockInventorySupplierPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StockInventoryNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(Role::findOrCreate('Super Admin', 'web'));
        $this->actingAs($user);
    }

    public function test_category_vendor_and_payment_pages_render(): void
    {
        $supplier = StockInventorySupplier::create([
            'name' => 'Reference Vendor',
            'phone' => '01700000000',
            'email' => 'vendor@example.test',
        ]);

        $this->get(route('stock-inventory.categories'))
            ->assertOk()
            ->assertSee('Item Categories');

        $this->get(route('stock-inventory.vendors'))
            ->assertOk()
            ->assertSee('Reference Vendor')
            ->assertSee('Vendor Register');

        $this->get(route('stock-inventory.vendor-payments'))
            ->assertOk()
            ->assertSee('Record Vendor Payment')
            ->assertSee('Vendor Payment Ledger');

        $this->assertDatabaseHas('stock_inventory_suppliers', ['id' => $supplier->id, 'name' => 'Reference Vendor']);
    }

    public function test_vendor_payment_is_validated_and_recorded(): void
    {
        $supplier = StockInventorySupplier::create(['name' => 'Payment Test Vendor']);

        $this->post(route('stock-inventory.vendor-payments.store'), [
            'supplier_id' => $supplier->id,
            'payment_date' => now()->toDateString(),
            'amount' => '1250.50',
            'method' => 'bank',
            'reference' => 'BANK-REF-17',
            'notes' => 'Supplier settlement',
        ])->assertRedirect()->assertSessionHas('inventory_message', 'Supplier payment recorded.');

        $this->assertDatabaseHas('stock_inventory_supplier_payments', [
            'supplier_id' => $supplier->id,
            'amount' => '1250.50',
            'method' => 'bank',
            'reference' => 'BANK-REF-17',
        ]);
    }
}
