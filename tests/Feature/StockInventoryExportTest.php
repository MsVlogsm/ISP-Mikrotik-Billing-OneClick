<?php

namespace Tests\Feature;

use App\Models\StockInventoryProduct;
use App\Models\StockInventoryMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StockInventoryExportTest extends TestCase
{
    use RefreshDatabase;

    private function loginAsAdmin(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(Role::findByName('Admin', 'web'));
        $this->actingAs($user);
    }

    public function test_opening_quantity_creates_a_stock_in_ledger_entry(): void
    {
        $this->loginAsAdmin();

        $this->post('/stock-inventory/products', [
            'sku' => 'OPENING-LEDGER-TEST',
            'name' => 'Opening Ledger Test',
            'unit' => 'piece',
            'quantity' => 7,
            'reorder_level' => 2,
            'unit_cost' => 12.50,
        ])->assertRedirect()->assertSessionHas('inventory_message', 'Product added.');

        $product = StockInventoryProduct::where('sku', 'OPENING-LEDGER-TEST')->firstOrFail();
        $this->assertDatabaseHas('stock_inventory_movements', [
            'product_id' => $product->id,
            'movement_type' => 'stock-in',
            'quantity' => 7,
            'reference' => 'Opening Balance',
        ]);
    }

    public function test_csv_export_has_headers_and_escapes_special_characters(): void
    {
        $this->loginAsAdmin();
        $product = StockInventoryProduct::create([
            'sku' => 'CSV-TEST',
            'name' => "Widget, Pro\nEdition",
            'unit' => 'piece',
            'quantity' => 4,
            'reorder_level' => 1,
            'unit_cost' => 10,
            'status' => 'active',
        ]);
        StockInventoryMovement::create([
            'product_id' => $product->id,
            'movement_type' => 'stock-in',
            'quantity' => 4,
            'reference' => 'Invoice, 42',
            'source' => 'Test',
        ]);

        $response = $this->get('/stock-inventory/export/movements.csv');
        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $response->assertHeader('content-disposition', 'attachment; filename="stock-movements.csv"');
        $this->assertStringContainsString('SKU,Product,Type,Quantity,Reference,Created', $response->getContent());
        $this->assertStringContainsString('"Widget, Pro', $response->getContent());
        $this->assertStringContainsString('"Invoice, 42"', $response->getContent());
    }

    public function test_xlsx_export_returns_an_excel_zip_payload(): void
    {
        $this->loginAsAdmin();
        StockInventoryProduct::create([
            'sku' => 'XLSX-TEST',
            'name' => 'Excel Test Product',
            'unit' => 'piece',
            'quantity' => 5,
            'reorder_level' => 2,
            'unit_cost' => 15,
            'status' => 'active',
        ]);

        $response = $this->get('/stock-inventory/export/products.xlsx');
        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->assertHeader('content-disposition', 'attachment; filename="stock-products.xlsx"');
        $this->assertSame('PK', substr($response->getContent(), 0, 2));
        $this->assertGreaterThan(100, strlen($response->getContent()));
    }

    public function test_stock_reconciliation_calculates_opening_net_and_closing_quantity(): void
    {
        $this->loginAsAdmin();
        $product = StockInventoryProduct::create([
            'sku' => 'RECON-TEST',
            'name' => 'Reconciliation Test Product',
            'unit' => 'piece',
            'quantity' => 10,
            'reorder_level' => 1,
            'unit_cost' => 10,
            'status' => 'active',
        ]);

        StockInventoryMovement::create([
            'product_id' => $product->id,
            'movement_type' => 'stock-in',
            'quantity' => 5,
            'reference' => 'RECON-IN',
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);
        StockInventoryMovement::create([
            'product_id' => $product->id,
            'movement_type' => 'issue',
            'quantity' => 2,
            'reference' => 'RECON-OUT',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $response = $this->get('/stock-inventory/reports');
        $response->assertOk();
        $rows = $response->viewData('reconciliation');
        $row = $rows->firstWhere('product.id', $product->id);
        $this->assertNotNull($row);
        $this->assertSame(7, $row['opening']);
        $this->assertSame(5, $row['stock_in']);
        $this->assertSame(2, $row['out']);
        $this->assertSame(3, $row['period_net']);
        $this->assertSame(10, $row['closing']);
    }

    public function test_stock_reconciliation_report_renders_for_admin(): void
    {
        $this->loginAsAdmin();
        $this->get('/stock-inventory/reports')->assertOk();
    }
}
