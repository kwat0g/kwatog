<?php

declare(strict_types=1);

namespace App\Common\Exports;

use App\Modules\Auth\Models\User;

/**
 * WS-E.1 — Contract every resource-specific exporter implements.
 *
 *   - permission(User): string  → which permission slug to gate on.
 *   - headers(): string[]       → CSV / XLSX column titles in order.
 *   - rows(filters): iterable   → yields one array per row (same order
 *                                 as headers()). Generators preferred so
 *                                 a 50k-row export does not load into RAM.
 *   - filename(): string        → file basename (without extension).
 *
 * Builders should respect the requesting user's data scope (e.g. dept-
 * scoped HR officer should only export their dept's employees) — that
 * filter belongs in the builder's rows() method.
 */
interface ExportBuilder
{
    public function permission(): string;

    /** @return array<int, string> */
    public function headers(): array;

    /**
     * @param  array<string, mixed>  $filters  Same query string the
     *                                          equivalent list endpoint
     *                                          accepts (search, status, …).
     * @return iterable<int, array<int, mixed>>
     */
    public function rows(array $filters, User $requester): iterable;

    public function filename(): string;
}
