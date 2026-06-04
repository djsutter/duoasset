<?php

namespace App\Services\Reports\Acb;

use App\Data\Reports\AssetAcbAuditRowData;

final class AssetAcbAuditLedgerResult
{
    /**
     * @param  AssetAcbAuditRowData[]  $rows
     * @param  array<int, LedgerAnnotationData[]>  $annotations
     */
    public function __construct(
        public readonly array $rows,
        public readonly ?array $annotations = null,
        public readonly ?array $diagnostics = [],
    ) {}
}
