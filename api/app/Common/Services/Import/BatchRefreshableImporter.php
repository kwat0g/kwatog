<?php

declare(strict_types=1);

namespace App\Common\Services\Import;

/** Importers that need one domain refresh after an atomic batch write. */
interface BatchRefreshableImporter
{
    public function stageBatchRefresh(string $action): void;
}
