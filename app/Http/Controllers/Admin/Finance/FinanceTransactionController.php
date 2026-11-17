<?php

namespace App\Http\Controllers\Admin\Finance;

use App\Audit\AuditLogger;
use App\Enums\Currency;
use App\Enums\FinanceType;
use App\Enums\PaymentMethod;
use App\Http\AdminErrorResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Finance\FinanceIndexRequest;
use App\Http\Requests\Admin\Finance\FinanceReceiptRequest;
use App\Http\Requests\Admin\Finance\FinanceTransactionRequest;
use App\Http\Resources\Admin\Finance\FinanceCategoryResource;
use App\Http\Resources\Admin\Finance\FinanceTransactionResource;
use App\Models\FinanceBook;
use App\Models\FinanceTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Finanzas del propietario: movimientos de ingresos y gastos de un libro.
 * Todas las rutas exigen sesión, 2FA y el permiso «manage-finances» (solo owner).
 * Los movimientos se resuelven dentro del libro de la URL (scopeBindings): uno de otro libro da 404.
 */
class FinanceTransactionController extends Controller
{
    private const DISK = 'finance';

    /** Extensión según el tipo de archivo detectado (nunca la que manda el navegador). */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    /** GET /finance/books/{book}/options — valores para formularios y filtros del libro. */
    public function options(Request $request, FinanceBook $book): JsonResponse
    {
        $categories = $book->categories()->withCount('transactions')->orderBy('type')->orderBy('sort')->orderBy('name')->get();
        $timezone = (string) config('mykesystems.admin_api.timezone', 'UTC');

        return response()->json([
            'data' => [
                'book' => ['id' => $book->id, 'name' => $book->name, 'kind' => $book->kind->value, 'is_archived' => $book->is_archived],
                'types' => array_map(fn (FinanceType $t) => ['value' => $t->value, 'label' => $t->label()], FinanceType::cases()),
                'currencies' => array_map(fn (Currency $c) => ['value' => $c->value, 'label' => $c->label(), 'symbol' => $c->symbol()], Currency::cases()),
                'payment_methods' => array_map(fn (PaymentMethod $p) => ['value' => $p->value, 'label' => $p->label()], PaymentMethod::cases()),
                'categories' => FinanceCategoryResource::collection($categories)->resolve($request),
                'current_month' => now($timezone)->format('Y-m'),
                'today' => now($timezone)->toDateString(),
                'receipt_max_kb' => FinanceReceiptRequest::MAX_KB,
            ],
        ]);
    }

    /** GET /finance/books/{book}/transactions */
    public function index(FinanceIndexRequest $request, FinanceBook $book): JsonResponse
    {
        $v = $request->validated();
        $query = $book->transactions()->with('category:id,name');

        if (! empty($v['month'])) {
            $start = Carbon::createFromFormat('!Y-m', $v['month']);
            // Rango semiabierto [día 1, día 1 del mes siguiente): funciona igual en MySQL y SQLite.
            $query->where('occurred_on', '>=', $start->toDateString())
                ->where('occurred_on', '<', $start->copy()->addMonthNoOverflow()->toDateString());
        }
        foreach (['type', 'currency', 'category_id'] as $filter) {
            if (! empty($v[$filter])) {
                $query->where($filter, $v[$filter]);
            }
        }
        if (! empty($v['search'])) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v['search']).'%';
            $query->where(fn ($q) => $q->where('description', 'like', $term)->orWhere('notes', 'like', $term));
        }

        $page = $query->orderByDesc('occurred_on')->orderByDesc('id')->paginate((int) ($v['per_page'] ?? 50));

        return response()->json([
            'data' => FinanceTransactionResource::collection($page->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
        ]);
    }

    /** GET /finance/books/{book}/transactions/{transaction} */
    public function show(Request $request, FinanceBook $book, FinanceTransaction $transaction): JsonResponse
    {
        return $this->resource($request, $transaction);
    }

    /** POST /finance/books/{book}/transactions */
    public function store(FinanceTransactionRequest $request, FinanceBook $book): JsonResponse
    {
        $transaction = $book->transactions()->create([...$request->transactionData(), 'created_by' => $request->user()->getKey()]);
        AuditLogger::record('finance.transaction.created', $request->user(), $transaction);

        return $this->resource($request, $transaction, 201);
    }

    /** PATCH /finance/books/{book}/transactions/{transaction} */
    public function update(FinanceTransactionRequest $request, FinanceBook $book, FinanceTransaction $transaction): JsonResponse
    {
        $transaction->update($request->transactionData());
        AuditLogger::record('finance.transaction.updated', $request->user(), $transaction);

        return $this->resource($request, $transaction);
    }

    /** DELETE /finance/books/{book}/transactions/{transaction} — borrado lógico (el comprobante se conserva). */
    public function destroy(Request $request, FinanceBook $book, FinanceTransaction $transaction): Response
    {
        $transaction->delete();
        AuditLogger::record('finance.transaction.deleted', $request->user(), $transaction);

        return response()->noContent();
    }

    /** POST …/transactions/{transaction}/receipt — adjunta o reemplaza el comprobante. */
    public function storeReceipt(FinanceReceiptRequest $request, FinanceBook $book, FinanceTransaction $transaction): JsonResponse
    {
        $file = $request->file('receipt');
        $mime = (string) $file->getMimeType();
        $extension = self::EXTENSIONS[$mime] ?? null;
        if ($extension === null) {
            return response()->json([
                'message' => 'Sube una foto (JPG, PNG o WEBP) o un PDF.',
                'errors' => ['receipt' => ['Sube una foto (JPG, PNG o WEBP) o un PDF.']],
            ], 422);
        }

        $path = $file->storeAs(
            'receipts/'.$book->id.'/'.$transaction->occurred_on->format('Y/m'),
            Str::uuid()->toString().'.'.$extension,
            self::DISK,
        );
        if ($path === false) {
            return AdminErrorResponse::make(500, code: 'receipt_not_saved', message: 'No se pudo guardar el comprobante. Inténtalo de nuevo.');
        }

        $previous = $transaction->receipt_path;
        $transaction->forceFill([
            'receipt_path' => $path,
            'receipt_name' => self::safeName($file->getClientOriginalName(), $extension),
            'receipt_mime' => $mime,
            'receipt_size' => (int) $file->getSize(),
        ])->save();

        if ($previous && $previous !== $path) {
            Storage::disk(self::DISK)->delete($previous);
        }
        AuditLogger::record('finance.receipt.uploaded', $request->user(), $transaction);

        return $this->resource($request, $transaction);
    }

    /** GET …/transactions/{transaction}/receipt — descarga privada del comprobante. */
    public function showReceipt(Request $request, FinanceBook $book, FinanceTransaction $transaction): Response
    {
        $disk = Storage::disk(self::DISK);
        if (! $transaction->hasReceipt() || ! $disk->exists((string) $transaction->receipt_path)) {
            return AdminErrorResponse::make(404, code: 'receipt_not_found', message: 'Este movimiento no tiene comprobante.');
        }

        $isImage = str_starts_with((string) $transaction->receipt_mime, 'image/');

        // Imágenes: se muestran en el navegador. PDF: se descarga (no se ejecuta nada en el dominio del panel).
        return $disk->response((string) $transaction->receipt_path, (string) $transaction->receipt_name, [
            'Content-Type' => (string) $transaction->receipt_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ], $isImage ? 'inline' : 'attachment');
    }

    /** DELETE …/transactions/{transaction}/receipt */
    public function destroyReceipt(Request $request, FinanceBook $book, FinanceTransaction $transaction): JsonResponse
    {
        if ($transaction->hasReceipt()) {
            Storage::disk(self::DISK)->delete((string) $transaction->receipt_path);
            $transaction->forceFill(['receipt_path' => null, 'receipt_name' => null, 'receipt_mime' => null, 'receipt_size' => null])->save();
            AuditLogger::record('finance.receipt.deleted', $request->user(), $transaction);
        }

        return $this->resource($request, $transaction);
    }

    private function resource(Request $request, FinanceTransaction $transaction, int $status = 200): JsonResponse
    {
        $transaction->load('category:id,name');

        return response()->json(['data' => (new FinanceTransactionResource($transaction))->resolve($request)], $status);
    }

    /** Nombre seguro para mostrar y descargar (sin rutas ni caracteres de control). */
    private static function safeName(string $original, string $extension): string
    {
        $base = pathinfo($original, PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^\pL\pN ._()-]+/u', '', $base));
        $base = Str::limit($base !== '' ? $base : 'comprobante', 120, '');

        return $base.'.'.$extension;
    }
}
