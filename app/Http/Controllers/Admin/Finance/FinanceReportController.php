<?php

namespace App\Http\Controllers\Admin\Finance;

use App\Audit\AuditLogger;
use App\Enums\Currency;
use App\Enums\FinanceType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\Finance\FinanceTransactionResource;
use App\Models\FinanceBook;
use App\Models\FinanceTransaction;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reporte mensual de un libro de finanzas (solo propietario).
 * Lempiras y dólares se totalizan por separado: nunca se convierten entre sí.
 */
class FinanceReportController extends Controller
{
    /** GET /finance/books/{book}/report?month=AAAA-MM */
    public function show(Request $request, FinanceBook $book): JsonResponse
    {
        $month = $this->month($request);
        $previous = $month->copy()->subMonthNoOverflow();

        $transactions = $this->monthQuery($book, $month)->with('category:id,name')->orderBy('occurred_on')->orderBy('id')->get();

        return response()->json([
            'data' => [
                'book' => ['id' => $book->id, 'name' => $book->name, 'kind' => $book->kind->value],
                'month' => $month->format('Y-m'),
                'previous_month' => $previous->format('Y-m'),
                'currencies' => array_map(fn (Currency $c) => $this->currencySummary($book, $c, $month, $previous), Currency::cases()),
                'transactions' => FinanceTransactionResource::collection($transactions)->resolve($request),
            ],
        ]);
    }

    /** GET /finance/books/{book}/report/export?month=AAAA-MM — CSV para Excel (UTF-8 con BOM). */
    public function export(Request $request, FinanceBook $book): StreamedResponse
    {
        $month = $this->month($request);
        $transactions = $this->monthQuery($book, $month)->with('category:id,name')->orderBy('occurred_on')->orderBy('id')->get();
        $summaries = array_map(fn (Currency $c) => $this->currencySummary($book, $c, $month, $month->copy()->subMonthNoOverflow()), Currency::cases());

        AuditLogger::record('finance.report.exported', $request->user(), $book, $book->id.':'.$month->format('Y-m'));

        $slug = Str::slug($book->name) ?: 'libro-'.$book->id;
        $filename = 'finanzas-'.$slug.'-'.$month->format('Y-m').'.csv';
        $bookName = $book->name;

        return response()->streamDownload(function () use ($transactions, $summaries, $month, $bookName) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Reporte de finanzas', self::cell($bookName), $month->format('Y-m')], escape: '');
            fputcsv($out, [], escape: '');
            fputcsv($out, ['Fecha', 'Tipo', 'Categoría', 'Descripción', 'Forma de pago', 'Moneda', 'Monto', 'Notas', 'Comprobante'], escape: '');
            foreach ($transactions as $t) {
                /** @var FinanceTransaction $t */
                fputcsv($out, array_map([self::class, 'cell'], [
                    $t->occurred_on->toDateString(),
                    $t->type->label(),
                    $t->category?->name ?? '',
                    $t->description,
                    $t->payment_method?->label() ?? '',
                    $t->currency->value,
                    ($t->type === FinanceType::Expense ? '-' : '').Money::format($t->amount_cents),
                    (string) $t->notes,
                    $t->hasReceipt() ? 'Sí' : 'No',
                ]), escape: '');
            }
            fputcsv($out, [], escape: '');
            fputcsv($out, ['Resumen', 'Moneda', 'Ingresos', 'Gastos', 'Saldo', 'Movimientos'], escape: '');
            foreach ($summaries as $s) {
                fputcsv($out, ['', $s['currency']['value'], $s['totals']['income'], $s['totals']['expense'], $s['totals']['balance'], $s['totals']['count']], escape: '');
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Mes pedido (AAAA-MM) o el actual en la zona horaria del panel. */
    private function month(Request $request): Carbon
    {
        $validated = $request->validate(['month' => ['nullable', 'date_format:Y-m']], ['month.date_format' => 'Usa el formato AAAA-MM.']);
        $timezone = (string) config('mykesystems.admin_api.timezone', 'UTC');

        return ! empty($validated['month'])
            ? Carbon::createFromFormat('!Y-m', $validated['month'])
            : Carbon::createFromFormat('!Y-m', now($timezone)->format('Y-m'));
    }

    /** @return \Illuminate\Database\Eloquent\Builder<FinanceTransaction> */
    private function monthQuery(FinanceBook $book, Carbon $month)
    {
        return FinanceTransaction::query()
            ->where('finance_transactions.book_id', $book->id)
            ->where('finance_transactions.occurred_on', '>=', $month->copy()->startOfMonth()->toDateString())
            ->where('finance_transactions.occurred_on', '<', $month->copy()->startOfMonth()->addMonthNoOverflow()->toDateString());
    }

    /** @return array<string, mixed> */
    private function currencySummary(FinanceBook $book, Currency $currency, Carbon $month, Carbon $previous): array
    {
        $totals = $this->totals($book, $currency, $month);
        $prev = $this->totals($book, $currency, $previous);

        $byCategory = $this->monthQuery($book, $month)
            ->where('finance_transactions.currency', $currency->value)
            ->join('finance_categories', 'finance_categories.id', '=', 'finance_transactions.category_id')
            ->groupBy('finance_transactions.type', 'finance_transactions.category_id', 'finance_categories.name')
            ->selectRaw('finance_transactions.type as type, finance_transactions.category_id as category_id, finance_categories.name as name, SUM(amount_cents) as cents, COUNT(*) as count')
            ->orderByDesc('cents')
            ->toBase()
            ->get();

        $categories = fn (FinanceType $type) => $byCategory
            ->where('type', $type->value)
            ->map(function ($row) use ($totals, $type) {
                $cents = (int) $row->cents;
                $total = $totals[$type === FinanceType::Income ? 'income_cents' : 'expense_cents'];

                return [
                    'category_id' => (int) $row->category_id,
                    'name' => (string) $row->name,
                    'cents' => $cents,
                    'amount' => Money::format($cents),
                    'count' => (int) $row->count,
                    'share' => $total > 0 ? round($cents * 100 / $total, 1) : 0.0,
                ];
            })
            ->values()
            ->all();

        return [
            'currency' => ['value' => $currency->value, 'label' => $currency->label(), 'symbol' => $currency->symbol()],
            'totals' => $this->formatted($totals),
            'previous' => $this->formatted($prev),
            'expense_by_category' => $categories(FinanceType::Expense),
            'income_by_category' => $categories(FinanceType::Income),
        ];
    }

    /** @return array{income_cents:int, expense_cents:int, count:int} */
    private function totals(FinanceBook $book, Currency $currency, Carbon $month): array
    {
        $row = $this->monthQuery($book, $month)
            ->where('finance_transactions.currency', $currency->value)
            ->toBase()
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN type = ? THEN amount_cents ELSE 0 END), 0) AS income_cents, '.
                'COALESCE(SUM(CASE WHEN type = ? THEN amount_cents ELSE 0 END), 0) AS expense_cents, '.
                'COUNT(*) AS count',
                [FinanceType::Income->value, FinanceType::Expense->value]
            )
            ->first();

        return [
            'income_cents' => (int) ($row->income_cents ?? 0),
            'expense_cents' => (int) ($row->expense_cents ?? 0),
            'count' => (int) ($row->count ?? 0),
        ];
    }

    /** @param array{income_cents:int, expense_cents:int, count:int} $t */
    private function formatted(array $t): array
    {
        $balance = $t['income_cents'] - $t['expense_cents'];

        return [
            'income_cents' => $t['income_cents'],
            'expense_cents' => $t['expense_cents'],
            'balance_cents' => $balance,
            'income' => Money::format($t['income_cents']),
            'expense' => Money::format($t['expense_cents']),
            'balance' => Money::format($balance),
            'count' => $t['count'],
        ];
    }

    /** Evita fórmulas en Excel (=, +, -, @ al inicio de un texto). Los montos se escriben aparte. */
    public static function cell(string $value): string
    {
        $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);

        return preg_match('/^[=+\-@\t]/', $value) && ! preg_match('/^-?\d+(\.\d{1,2})?$/', $value) ? "'".$value : $value;
    }
}
