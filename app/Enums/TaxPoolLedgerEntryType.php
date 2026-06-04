<?php

namespace App\Enums;

enum TaxPoolLedgerEntryType: string
{
    case Acquisition = 'acquisition';
    case Disposition = 'disposition';
    case TransferFee = 'transfer_fee';
    case DeniedLossAdjustment = 'denied_loss_adjustment';
}
