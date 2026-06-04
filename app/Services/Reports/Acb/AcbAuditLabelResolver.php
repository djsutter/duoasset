<?php

namespace App\Services\Reports\Acb;

use App\Data\Reports\AssetAcbAuditRowData;

final class AcbAuditLabelResolver
{
    public function labelFor(AssetAcbAuditRowData $row): string
    {
        return $row->event_type->label();
    }
}
