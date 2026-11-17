<?php

namespace App\Http\Controllers\Admin\Finance;

use App\Audit\AuditLogger;
use App\Enums\Currency;
use App\Enums\FinanceBookKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Finance\FinanceBookRequest;
use App\Models\FinanceBook;
use App\Models\FinanceTransaction;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Libros de finanzas del propietario (Personal, Empresa y los que cree).
 * Los libros no se eliminan: se archivan, para no perder movimientos.
 */
class FinanceBookController extends Controller
{
    /** GET /finance/books — libros con el resumen del mes actual por moneda. */
    public function index(Request $request): JsonResponse
    {
        $timezone = (string) config('mykesystems.admin_api.timezone', 'UTC');
        $month = Carbon::createFromFormat('!Y-m', now($timezone)->format('Y-m'));
        $books = FinanceBook::query()->withCount('transactions')->orderBy('is_archived')->orderBy('sort')->orderBy('name')->get();
        $totals = $this->monthTotals($month);

        return response()->json([
            'data' => $books->map(fn (FinanceBook $book) => $this->present($book, $totals->get($book->id, collect())))->values(),
            'meta' => [
                'current_month' => $month->format('Y-m'),
                'kinds' => array_map(fn (FinanceBookKind $k) => ['value' => $k->value, 'label' => $k->label()], FinanceBookKind::cases()),
            ],
        ]);
    }

    /** GET /finance/books/{book} */
    public function show(Request $request, FinanceBook $book): JsonResponse
    {
        $book->loadCount('transactions');

        return response()->json(['data' => $this->present($book, null)]);
    }

    /** POST /finance/books — crea el libro con las categorías iniciales de su tipo. */
    public function store(FinanceBookRequest $request): JsonResponse
    {
        $v = $request->validated();
        $kind = FinanceBookKind::from($v['kind']);

        $book = DB::transaction(function () use ($v, $kind) {
            $book = FinanceBook::create([
                'name' => $v['name'],
                'kind' => $kind,
                'sort' => (int) FinanceBook::query()->max('sort') + 1,
            ]);
            $book->seedCategories($kind);

            return $book;
        });
        AuditLogger::record('finance.book.created', $request->user(), $book);

        return response()->json(['data' => $this->present($book->loadCount('transactions'), null)], 201);
    }

    /** PATCH /finance/books/{book} — renombrar o archivar/reactivar. */
    public function update(FinanceBookRequest $request, FinanceBook $book): JsonResponse
    {
        $book->update($request->safe()->only(['name', 'is_archived']));
        AuditLogger::record('finance.book.updated', $request->user(), $book);

        return response()->json(['data' => $this->present($book->loadCount('transactions'), null)]);
    }

    /**
     * @param  Collection<int, object>|null  $rows  totales del mes (moneda, tipo, centavos)
     * @return array<string, mixed>
     */
    private function present(FinanceBook $book, ?Collection $rows): array
    {
        $data = [
            'id' => $book->id,
            'name' => $book->name,
            'kind' => ['value' => $book->kind->value, 'label' => $book->kind->label()],
            'is_archived' => $book->is_archived,
            'transactions_count' => (int) ($book->transactions_count ?? 0),
        ];

        if ($rows !== null) {
            $data['month'] = array_map(function (Currency $currency) use ($rows) {
                $income = (int) $rows->where('currency', $currency->value)->where('type', 'income')->sum('cents');
                $expense = (int) $rows->where('currency', $currency->value)->where('type', 'expense')->sum('cents');

                return [
                    'currency' => ['value' => $currency->value, 'label' => $currency->label(), 'symbol' => $currency->symbol()],
                    'income_cents' => $income,
                    'expense_cents' => $expense,
                    'balance_cents' => $income - $expense,
                    'balance' => Money::format($income - $expense),
                ];
            }, Currency::cases());
        }

        return $data;
    }

    /** @return Collection<int, Collection<int, object>> totales del mes agrupados por libro */
    private function monthTotals(Carbon $month): Collection
    {
        return FinanceTransaction::query()
            ->where('occurred_on', '>=', $month->toDateString())
            ->where('occurred_on', '<', $month->copy()->addMonthNoOverflow()->toDateString())
            ->groupBy('book_id', 'currency', 'type')
            ->selectRaw('book_id, currency, type, SUM(amount_cents) as cents')
            ->toBase()
            ->get()
            ->groupBy('book_id');
    }
}
