<?php

namespace App\Queries;

use App\Enums\LeadStatus;
use App\Models\Lead;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filtros, búsqueda y orden del listado de solicitudes del panel.
 * Recibe datos ya validados por LeadIndexRequest.
 */
final class AdminLeadQuery
{
    /** Grupos de estados usados por el dashboard y los filtros. */
    public const STATUS_GROUPS = [
        'en_proceso' => [LeadStatus::Contactado, LeadStatus::EnNegociacion],
    ];

    public const SORTS = [
        '-created_at' => ['created_at', 'desc'],
        'created_at' => ['created_at', 'asc'],
        '-status_changed_at' => ['status_changed_at', 'desc'],
    ];

    /**
     * @param  array{search?:?string, status?:list<string>, service?:list<string>, date_from?:?string, date_to?:?string, sort?:?string}  $filters
     * @return Builder<Lead>
     */
    public static function build(array $filters): Builder
    {
        $query = Lead::query()->withCount('notes');

        if (! empty($filters['status'])) {
            $query->whereIn('status', self::expandStatuses($filters['status']));
        }

        if (! empty($filters['service'])) {
            $query->whereIn('service', $filters['service']);
        }

        $timezone = (string) config('mykesystems.admin_api.timezone', 'UTC');
        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', CarbonImmutable::createFromFormat('!Y-m-d', $filters['date_from'], $timezone)->startOfDay()->utc());
        }
        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', CarbonImmutable::createFromFormat('!Y-m-d', $filters['date_to'], $timezone)->endOfDay()->utc());
        }

        if (! empty($filters['search'])) {
            self::applySearch($query, $filters['search']);
        }

        [$column, $direction] = self::SORTS[$filters['sort'] ?? '-created_at'] ?? self::SORTS['-created_at'];

        return $query->orderBy($column, $direction)->orderBy('id', $direction);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    public static function expandStatuses(array $values): array
    {
        $statuses = [];
        foreach ($values as $value) {
            foreach (self::STATUS_GROUPS[$value] ?? [LeadStatus::from($value)] as $status) {
                $statuses[] = $status->value;
            }
        }

        return array_values(array_unique($statuses));
    }

    /**
     * Cada palabra debe aparecer en nombre, apellido, correo, negocio, número de
     * solicitud o (si tiene 4 dígitos o más) en el teléfono. Los comodines de LIKE
     * que escriba el usuario se tratan como texto literal.
     *
     * @param  Builder<Lead>  $query
     */
    private static function applySearch(Builder $query, string $search): void
    {
        $terms = preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $terms = array_slice($terms, 0, (int) config('mykesystems.admin_api.search_max_terms', 5));

        foreach ($terms as $term) {
            $like = '%'.self::escapeLike($term).'%';
            $digits = preg_replace('/\D+/', '', $term) ?? '';

            $query->where(function (Builder $where) use ($like, $digits) {
                // ESCAPE '!' funciona igual en MariaDB/MySQL y SQLite.
                foreach (['first_name', 'last_name', 'email', 'business', 'public_id'] as $column) {
                    $where->orWhereRaw("{$column} LIKE ? ESCAPE '!'", [$like]);
                }

                if (strlen($digits) >= 4) {
                    $where->orWhereRaw("phone LIKE ? ESCAPE '!'", ['%'.$digits.'%']);
                }
            });
        }
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
