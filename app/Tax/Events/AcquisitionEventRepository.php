<?php

namespace App\Tax\Events;

use Illuminate\Support\Collection;

interface AcquisitionEventRepository
{
    /**
     * @return Collection<AcquisitionEvent>
     */
    public function recent(): Collection;
}
