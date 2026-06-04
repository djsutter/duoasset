<?php

namespace App\Enums;

enum AcbAuditAdjustmentReason: string
{
    case SuperficialLossReinstatement = 'superficial_loss_reinstatement';
    case SuperficialLossMarker = 'superficial_loss_marker';

    // future-proofing
    case PriorYearCorrection = 'prior_year_correction';
    case ManualAdjustment = 'manual_adjustment';
}
