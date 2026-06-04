<?php

namespace App\Services\Transactions\Entries;

use App\Data\Transactions\BaseTransactionData;

interface EntryBuilderInterface
{
    public function buildEntriesArray(BaseTransactionData $dto): array;
}
