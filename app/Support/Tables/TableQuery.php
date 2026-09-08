<?php

declare(strict_types=1);

namespace App\Support\Tables;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Reads the search, sort, filter and paging state out of a request and applies
 * it to a query.
 *
 * Every index screen in the ERP works the same way, so the rules live here
 * once: the sort column must be one the controller declared sortable, the page
 * size is bounded, and unknown filters are ignored rather than passed to the
 * database. A controller that forgot to whitelist a column cannot be talked
 * into ordering by one through the query string.
 */
final readonly class TableQuery
{
    private const DEFAULT_PER_PAGE = 25;

    private const ALLOWED_PER_PAGE = [10, 25, 50, 100, 200];

    /**
     * @param  array<string, string>  $filters
     */
    private function __construct(
        public string $search,
        public ?string $sort,
        public string $direction,
        public int $perPage,
        public array $filters,
    ) {}

    /**
     * @param  list<string>  $allowedFilters
     */
    public static function fromRequest(Request $request, array $allowedFilters = []): self
    {
        $perPage = (int) $request->integer('per_page', self::DEFAULT_PER_PAGE);

        $filters = [];

        foreach ($allowedFilters as $filter) {
            $value = $request->query($filter);

            if (is_string($value) && $value !== '' && $value !== 'all') {
                $filters[$filter] = $value;
            }
        }

        return new self(
            search: trim((string) $request->query('search', '')),
            sort: self::stringOrNull($request->query('sort')),
            direction: strtolower((string) $request->query('direction')) === 'desc' ? 'desc' : 'asc',
            perPage: in_array($perPage, self::ALLOWED_PER_PAGE, strict: true) ? $perPage : self::DEFAULT_PER_PAGE,
            filters: $filters,
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Order the query, ignoring any column the controller did not declare.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $sortable
     * @return Builder<TModel>
     */
    public function applySorting(Builder $query, array $sortable, string $fallback): Builder
    {
        $column = in_array($this->sort, $sortable, strict: true) ? $this->sort : $fallback;

        return $query->orderBy($column, $this->direction);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return LengthAwarePaginator<int, TModel>
     */
    public function paginate(Builder $query): LengthAwarePaginator
    {
        return $query->paginate($this->perPage)->withQueryString();
    }

    public function filter(string $key): ?string
    {
        return $this->filters[$key] ?? null;
    }

    /**
     * The state to hand back to the interface so its controls reflect the
     * query that actually ran, rather than what was asked for.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'search' => $this->search,
            'sort' => $this->sort,
            'direction' => $this->direction,
            'per_page' => $this->perPage,
            'filters' => (object) $this->filters,
        ];
    }
}
