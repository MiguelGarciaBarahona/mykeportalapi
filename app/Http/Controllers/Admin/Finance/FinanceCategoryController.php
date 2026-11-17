<?php

namespace App\Http\Controllers\Admin\Finance;

use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Finance\FinanceCategoryRequest;
use App\Http\Resources\Admin\Finance\FinanceCategoryResource;
use App\Models\FinanceBook;
use App\Models\FinanceCategory;
use Illuminate\Http\JsonResponse;

/**
 * Categorías de finanzas. No se eliminan (para no dejar movimientos sin categoría):
 * se desactivan y dejan de ofrecerse en los formularios.
 */
class FinanceCategoryController extends Controller
{
    /** POST /finance/books/{book}/categories */
    public function store(FinanceCategoryRequest $request, FinanceBook $book): JsonResponse
    {
        $v = $request->validated();
        $sort = (int) $book->categories()->where('type', $v['type'])->max('sort') + 1;
        /** @var FinanceCategory $category */
        $category = $book->categories()->create(['type' => $v['type'], 'name' => $v['name'], 'is_active' => $v['is_active'] ?? true, 'sort' => $sort]);
        AuditLogger::record('finance.category.created', $request->user(), $category);

        return response()->json(['data' => (new FinanceCategoryResource($category->loadCount('transactions')))->resolve($request)], 201);
    }

    /** PATCH /finance/books/{book}/categories/{category} */
    public function update(FinanceCategoryRequest $request, FinanceBook $book, FinanceCategory $category): JsonResponse
    {
        $category->update($request->safe()->only(['name', 'is_active']));
        AuditLogger::record('finance.category.updated', $request->user(), $category);

        return response()->json(['data' => (new FinanceCategoryResource($category->loadCount('transactions')))->resolve($request)]);
    }
}
