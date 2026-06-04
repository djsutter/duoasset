<?php

namespace App\Tax\SuperficialLoss\Domain;

enum SuperficialLossResolutionType: string
{
    case Expired = 'expired';
    case StillPending = 'still_pending';
}
