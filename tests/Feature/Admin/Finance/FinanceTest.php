<?php

namespace Tests\Feature\Admin\Finance;

use App\Http\Controllers\Admin\Finance\FinanceReportController;
use App\Models\FinanceBook;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithAdminPanel;
use Tests\TestCase;

/**
 * Módulo de finanzas personales: solo el propietario con 2FA.
 */
class FinanceTest extends TestCase
{
    use InteractsWithAdminPanel, RefreshDatabase;

    private User $owner;

    private FinanceBook $book;

    /** Prefijo de las rutas del libro «Personal». */
    private string $bp;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('finance');
        $this->owner = User::factory()->owner()->withTwoFactor()->create();
        $this->book = FinanceBook::query()->where('name', 'Personal')->firstOrFail();
        $this->bp = '/finance/books/'.$this->book->id;
    }

    private function expenseCategory(): FinanceCategory
    {
        return $this->book->categories()->where('type', 'expense')->where('is_active', true)->orderBy('sort')->firstOrFail();
    }

    private function incomeCategory(): FinanceCategory
    {
        return $this->book->categories()->where('type', 'income')->where('is_active', true)->orderBy('sort')->firstOrFail();
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return [
            'type' => 'expense',
            'occurred_on' => '2026-11-10',
            'amount' => '1250.50',
            'currency' => 'HNL',
            'category_id' => $this->expenseCategory()->id,
            'description' => 'Pago de internet',
            'payment_method' => 'card',
            'notes' => null,
            ...$overrides,
        ];
    }

    private function adminDelete(string $path): TestResponse
    {
        return $this->withHeaders($this->adminHeaders())->deleteJson($this->adminUrl($path));
    }

    private function uploadReceipt(int $id, UploadedFile $file): TestResponse
    {
        return $this->withHeaders($this->adminHeaders())->post($this->adminUrl("{$this->bp}/transactions/{$id}/receipt"), ['receipt' => $file]);
    }

    // --- Acceso ---------------------------------------------------------------

    public function test_sin_sesion_responde_401(): void
    {
        $this->adminGet($this->bp.'/transactions')->assertUnauthorized();
        $this->adminGet($this->bp.'/report')->assertUnauthorized();
    }

    public function test_un_admin_que_no_es_propietario_no_tiene_acceso(): void
    {
        $admin = User::factory()->withTwoFactor()->create();
        $this->actingAs($admin);

        $this->adminGet($this->bp.'/options')->assertForbidden();
        $this->adminGet($this->bp.'/transactions')->assertForbidden();
        $this->adminGet($this->bp.'/report')->assertForbidden();
        $this->adminGet($this->bp.'/report/export')->assertForbidden();
        $this->adminPost($this->bp.'/transactions', $this->payload())->assertForbidden();
        $this->assertDatabaseCount('finance_transactions', 0);

        $this->adminGet('/me')->assertOk()->assertJson(['permissions' => ['manage_finances' => false]]);
    }

    public function test_el_propietario_sin_2fa_no_tiene_acceso(): void
    {
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner)->adminGet($this->bp.'/transactions')->assertForbidden()->assertJson(['code' => 'two_factor_setup_required']);
    }

    public function test_el_propietario_ve_el_permiso_en_me(): void
    {
        $this->actingAs($this->owner)->adminGet('/me')->assertOk()->assertJson(['permissions' => ['manage_finances' => true]]);
    }

    // --- Opciones y categorías ------------------------------------------------

    public function test_opciones_incluyen_categorias_por_defecto(): void
    {
        $response = $this->actingAs($this->owner)->adminGet($this->bp.'/options')->assertOk();
        $names = collect($response->json('data.categories'))->pluck('name');

        $this->assertTrue($names->contains('Alimentación'));
        $this->assertTrue($names->contains('Proyectos y servicios'));
        $this->assertSame(['HNL', 'USD'], collect($response->json('data.currencies'))->pluck('value')->all());
        $this->assertSame(5120, $response->json('data.receipt_max_kb'));
    }

    public function test_crear_renombrar_y_desactivar_categoria(): void
    {
        $this->actingAs($this->owner);
        $id = $this->adminPost($this->bp.'/categories', ['type' => 'expense', 'name' => '  Mascotas  '])
            ->assertCreated()->assertJsonPath('data.name', 'Mascotas')->json('data.id');

        $this->adminPost($this->bp.'/categories', ['type' => 'expense', 'name' => 'Mascotas'])->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->adminPatch("{$this->bp}/categories/{$id}", ['name' => 'Mascota', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->adminPatch("{$this->bp}/categories/{$id}", ['type' => 'income'])->assertUnprocessable();

        $this->assertDatabaseHas('audit_logs', ['action' => 'finance.category.updated', 'subject_id' => (string) $id]);
    }

    // --- Movimientos -----------------------------------------------------------

    public function test_registrar_un_gasto_guarda_centavos_exactos_y_audita(): void
    {
        $response = $this->actingAs($this->owner)->adminPost($this->bp.'/transactions', $this->payload(['amount' => '1,250.5']))
            ->assertCreated()
            ->assertJsonPath('data.amount_cents', 125050)
            ->assertJsonPath('data.amount', '1250.50')
            ->assertJsonPath('data.currency.symbol', 'L')
            ->assertJsonPath('data.receipt', null);

        $id = $response->json('data.id');
        $this->assertDatabaseHas('finance_transactions', ['id' => $id, 'amount_cents' => 125050, 'created_by' => $this->owner->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'finance.transaction.created', 'subject_id' => (string) $id]);
    }

    public function test_validaciones_del_movimiento(): void
    {
        $this->actingAs($this->owner);

        foreach (['0', '-5', '12.345', 'abc', '1e3'] as $amount) {
            $this->adminPost($this->bp.'/transactions', $this->payload(['amount' => $amount]))->assertUnprocessable()->assertJsonValidationErrors('amount');
        }
        $this->adminPost($this->bp.'/transactions', $this->payload(['currency' => 'EUR']))->assertJsonValidationErrors('currency');
        $this->adminPost($this->bp.'/transactions', $this->payload(['occurred_on' => '10/11/2026']))->assertJsonValidationErrors('occurred_on');
        $this->adminPost($this->bp.'/transactions', $this->payload(['description' => '']))->assertJsonValidationErrors('description');
        $this->adminPost($this->bp.'/transactions', $this->payload(['category_id' => $this->incomeCategory()->id]))->assertJsonValidationErrors('category_id');

        $inactive = FinanceCategory::factory()->inactive()->create(['book_id' => $this->book->id]);
        $this->adminPost($this->bp.'/transactions', $this->payload(['category_id' => $inactive->id]))->assertJsonValidationErrors('category_id');

        $this->assertDatabaseCount('finance_transactions', 0);
    }

    public function test_editar_y_eliminar_movimiento(): void
    {
        $this->actingAs($this->owner);
        $id = $this->adminPost($this->bp.'/transactions', $this->payload())->json('data.id');

        $this->adminPatch("{$this->bp}/transactions/{$id}", $this->payload(['amount' => '99', 'currency' => 'USD']))
            ->assertOk()->assertJsonPath('data.amount_cents', 9900)->assertJsonPath('data.currency.value', 'USD');

        $this->adminDelete("{$this->bp}/transactions/{$id}")->assertNoContent();
        $this->assertSoftDeleted('finance_transactions', ['id' => $id]);
        $this->adminGet("{$this->bp}/transactions/{$id}")->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['action' => 'finance.transaction.deleted', 'subject_id' => (string) $id]);
    }

    public function test_listado_filtra_por_mes_tipo_moneda_y_busqueda(): void
    {
        $food = $this->expenseCategory();
        FinanceTransaction::factory()->create(['occurred_on' => '2026-11-01', 'category_id' => $food->id, 'description' => 'Supermercado']);
        FinanceTransaction::factory()->create(['occurred_on' => '2026-11-30', 'category_id' => $food->id, 'currency' => 'USD', 'description' => 'Dominio']);
        FinanceTransaction::factory()->income()->create(['occurred_on' => '2026-11-15', 'category_id' => $this->incomeCategory()->id]);
        FinanceTransaction::factory()->create(['occurred_on' => '2026-10-31', 'category_id' => $food->id]);
        FinanceTransaction::factory()->create(['occurred_on' => '2026-12-01', 'category_id' => $food->id]);

        $this->actingAs($this->owner);
        $this->assertSame(3, $this->adminGet($this->bp.'/transactions?month=2026-11')->assertOk()->json('meta.total'));
        $this->assertSame(2, $this->adminGet($this->bp.'/transactions?month=2026-11&type=expense')->json('meta.total'));
        $this->assertSame(1, $this->adminGet($this->bp.'/transactions?month=2026-11&currency=USD')->json('meta.total'));
        $this->assertSame(1, $this->adminGet($this->bp.'/transactions?month=2026-11&search=super')->json('meta.total'));
        $this->adminGet($this->bp.'/transactions?month=noviembre')->assertUnprocessable();

        $first = $this->adminGet($this->bp.'/transactions?month=2026-11')->json('data.0');
        $this->assertSame('2026-11-30', $first['occurred_on']);
    }

    // --- Comprobantes -----------------------------------------------------------

    public function test_subir_ver_y_quitar_comprobante(): void
    {
        $this->actingAs($this->owner);
        $id = $this->adminPost($this->bp.'/transactions', $this->payload())->json('data.id');

        $response = $this->uploadReceipt($id, UploadedFile::fake()->create('Factura ENEE noviembre.pdf', 200, 'application/pdf'))
            ->assertOk()
            ->assertJsonPath('data.receipt.mime', 'application/pdf')
            ->assertJsonPath('data.receipt.name', 'Factura ENEE noviembre.pdf');
        $this->assertArrayNotHasKey('receipt_path', $response->json('data'));

        $path = FinanceTransaction::find($id)->receipt_path;
        $this->assertStringStartsWith('receipts/'.$this->book->id.'/2026/11/', $path);
        Storage::disk('finance')->assertExists($path);

        $download = $this->withHeaders($this->adminHeaders())->get($this->adminUrl("{$this->bp}/transactions/{$id}/receipt"))->assertOk();
        $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $download->headers->get('X-Content-Type-Options'));

        // Reemplazar borra el archivo anterior
        $this->uploadReceipt($id, UploadedFile::fake()->create('foto.jpg', 300, 'image/jpeg'))->assertOk()->assertJsonPath('data.receipt.mime', 'image/jpeg');
        Storage::disk('finance')->assertMissing($path);

        $this->adminDelete("{$this->bp}/transactions/{$id}/receipt")->assertOk()->assertJsonPath('data.receipt', null);
        $this->assertCount(0, Storage::disk('finance')->allFiles());
        $this->assertDatabaseHas('audit_logs', ['action' => 'finance.receipt.deleted', 'subject_id' => (string) $id]);
    }

    public function test_comprobante_rechaza_tipos_y_tamanos_no_permitidos(): void
    {
        $this->actingAs($this->owner);
        $id = $this->adminPost($this->bp.'/transactions', $this->payload())->json('data.id');

        $this->uploadReceipt($id, UploadedFile::fake()->create('script.html', 10, 'text/html'))->assertUnprocessable()->assertJsonValidationErrors('receipt');
        $this->uploadReceipt($id, UploadedFile::fake()->create('grande.pdf', 6000, 'application/pdf'))->assertUnprocessable()->assertJsonValidationErrors('receipt');
        $this->assertCount(0, Storage::disk('finance')->allFiles());
    }

    public function test_un_admin_no_puede_descargar_comprobantes(): void
    {
        $this->actingAs($this->owner);
        $id = $this->adminPost($this->bp.'/transactions', $this->payload())->json('data.id');
        $this->uploadReceipt($id, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'))->assertOk();

        $this->actingAs(User::factory()->withTwoFactor()->create());
        $this->withHeaders($this->adminHeaders())->get($this->adminUrl("{$this->bp}/transactions/{$id}/receipt"))->assertForbidden();
    }

    // --- Reporte ----------------------------------------------------------------

    public function test_reporte_mensual_por_moneda_sin_convertir(): void
    {
        $food = $this->expenseCategory();
        $other = $this->book->categories()->where('type', 'expense')->where('id', '!=', $food->id)->firstOrFail();
        $income = $this->incomeCategory();

        FinanceTransaction::factory()->income()->create(['occurred_on' => '2026-11-05', 'category_id' => $income->id, 'amount_cents' => 2000000]);
        FinanceTransaction::factory()->create(['occurred_on' => '2026-11-06', 'category_id' => $food->id, 'amount_cents' => 300000]);
        FinanceTransaction::factory()->create(['occurred_on' => '2026-11-07', 'category_id' => $food->id, 'amount_cents' => 100000]);
        FinanceTransaction::factory()->create(['occurred_on' => '2026-11-08', 'category_id' => $other->id, 'amount_cents' => 100000]);
        FinanceTransaction::factory()->create(['occurred_on' => '2026-11-09', 'category_id' => $other->id, 'amount_cents' => 1500, 'currency' => 'USD']);
        FinanceTransaction::factory()->create(['occurred_on' => '2026-10-20', 'category_id' => $food->id, 'amount_cents' => 250000]);
        FinanceTransaction::factory()->create(['occurred_on' => '2026-11-10', 'category_id' => $food->id, 'amount_cents' => 999999])->delete();

        $data = $this->actingAs($this->owner)->adminGet($this->bp.'/report?month=2026-11')->assertOk()->json('data');

        $this->assertSame('2026-10', $data['previous_month']);
        $hnl = collect($data['currencies'])->firstWhere('currency.value', 'HNL');
        $usd = collect($data['currencies'])->firstWhere('currency.value', 'USD');

        $this->assertSame(2000000, $hnl['totals']['income_cents']);
        $this->assertSame(500000, $hnl['totals']['expense_cents']);
        $this->assertSame(1500000, $hnl['totals']['balance_cents']);
        $this->assertSame(4, $hnl['totals']['count']);
        $this->assertSame(250000, $hnl['previous']['expense_cents']);
        $this->assertSame($food->id, $hnl['expense_by_category'][0]['category_id']);
        $this->assertSame(400000, $hnl['expense_by_category'][0]['cents']);
        $this->assertEquals(80.0, $hnl['expense_by_category'][0]['share']);

        $this->assertSame(1500, $usd['totals']['expense_cents']);
        $this->assertSame(-1500, $usd['totals']['balance_cents']);
        $this->assertSame('-15.00', $usd['totals']['balance']);

        $this->assertCount(5, $data['transactions']);
    }

    public function test_exportar_csv_con_bom_y_sin_formulas(): void
    {
        FinanceTransaction::factory()->create([
            'occurred_on' => '2026-11-06',
            'category_id' => $this->expenseCategory()->id,
            'amount_cents' => 12345,
            'description' => '=HYPERLINK("http://x")',
        ]);

        $response = $this->actingAs($this->owner)->withHeaders($this->adminHeaders())->get($this->adminUrl($this->bp.'/report/export?month=2026-11'))->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('finanzas-personal-2026-11.csv', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('-123.45', $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'finance.report.exported', 'subject_id' => $this->book->id.':2026-11']);
    }

    // --- Libros -----------------------------------------------------------------

    public function test_existen_los_libros_personal_y_empresa_con_sus_categorias(): void
    {
        $response = $this->actingAs($this->owner)->adminGet('/finance/books')->assertOk();
        $this->assertSame(['Personal', 'Empresa'], collect($response->json('data'))->pluck('name')->all());
        $this->assertSame('HNL', $response->json('data.0.month.0.currency.value'));

        $empresa = FinanceBook::query()->where('name', 'Empresa')->firstOrFail();
        $this->assertTrue($empresa->categories()->where('name', 'Nómina y honorarios')->exists());
        $this->assertFalse($this->book->categories()->where('name', 'Nómina y honorarios')->exists());
    }

    public function test_crear_renombrar_y_archivar_un_libro(): void
    {
        $this->actingAs($this->owner);
        $id = $this->adminPost('/finance/books', ['name' => '  Casa de playa ', 'kind' => 'other'])
            ->assertCreated()->assertJsonPath('data.name', 'Casa de playa')->json('data.id');
        $this->assertSame(4, FinanceBook::find($id)->categories()->count());

        $this->adminPost('/finance/books', ['name' => 'Casa de playa', 'kind' => 'other'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->adminPost('/finance/books', ['name' => 'X2', 'kind' => 'banco'])->assertUnprocessable()->assertJsonValidationErrors('kind');

        $this->adminPatch("/finance/books/{$id}", ['name' => 'Casa', 'is_archived' => true])->assertOk()->assertJsonPath('data.is_archived', true);
        $this->adminPatch("/finance/books/{$id}", ['kind' => 'business'])->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['action' => 'finance.book.created', 'subject_id' => (string) $id]);
    }

    public function test_los_libros_estan_separados(): void
    {
        $this->actingAs($this->owner);
        $empresa = FinanceBook::query()->where('name', 'Empresa')->firstOrFail();
        $ep = '/finance/books/'.$empresa->id;

        $this->adminPost('/finance/transactions', $this->payload())->assertNotFound();

        $id = $this->adminPost($this->bp.'/transactions', $this->payload())->assertCreated()->json('data.id');

        // Un movimiento de Personal no se ve ni se edita desde Empresa.
        $this->adminGet("{$ep}/transactions/{$id}")->assertNotFound();
        $this->adminPatch("{$ep}/transactions/{$id}", $this->payload())->assertNotFound();
        $this->assertSame(0, $this->adminGet("{$ep}/transactions?month=2026-11")->json('meta.total'));
        $this->assertSame(1, $this->adminGet($this->bp.'/transactions?month=2026-11')->json('meta.total'));

        // Una categoría de Personal no sirve en Empresa.
        $this->adminPost("{$ep}/transactions", $this->payload())->assertUnprocessable()->assertJsonValidationErrors('category_id');

        // El reporte de Empresa no incluye lo de Personal.
        $hnl = collect($this->adminGet("{$ep}/report?month=2026-11")->json('data.currencies'))->firstWhere('currency.value', 'HNL');
        $this->assertSame(0, $hnl['totals']['expense_cents']);

        // Categorías de otro libro: 404 por la ruta.
        $personalCategory = $this->expenseCategory();
        $this->adminPatch("{$ep}/categories/{$personalCategory->id}", ['name' => 'Otra'])->assertNotFound();

        // Mismo nombre de categoría permitido en libros distintos.
        $this->adminPost("{$ep}/categories", ['type' => 'expense', 'name' => 'Alimentación'])->assertCreated();
    }

    public function test_un_admin_no_ve_los_libros(): void
    {
        $this->actingAs(User::factory()->withTwoFactor()->create());
        $this->adminGet('/finance/books')->assertForbidden();
        $this->adminPost('/finance/books', ['name' => 'Mío', 'kind' => 'other'])->assertForbidden();
        $this->assertDatabaseMissing('finance_books', ['name' => 'Mío']);
    }

    // --- Utilidades -------------------------------------------------------------

    public function test_conversion_de_montos(): void
    {
        $this->assertSame(100, Money::toCents('1'));
        $this->assertSame(150, Money::toCents('1.5'));
        $this->assertSame(123456789, Money::toCents('1,234,567.89'));
        $this->assertNull(Money::toCents('0.00'));
        $this->assertNull(Money::toCents('1.999'));
        $this->assertNull(Money::toCents(1.5));
        $this->assertSame('0.05', Money::format(5));
        $this->assertSame('-10.00', Money::format(-1000));
        $this->assertSame("'@SUM(A1)", FinanceReportController::cell('@SUM(A1)'));
        $this->assertSame('-15.00', FinanceReportController::cell('-15.00'));
    }
}
