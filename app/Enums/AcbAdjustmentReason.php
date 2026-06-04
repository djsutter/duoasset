<?php

namespace App\Enums;

enum AcbAdjustmentReason: string
{
    case SuperficialLossDenied = 'superficial_loss_denied';
    case SuperficialLossReinstatement = 'superficial_loss_reinstatement';
    case PriorYearCorrection = 'prior_year_correction';
    case ManualAdjustment = 'manual_adjustment';
}
