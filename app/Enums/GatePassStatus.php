<?php

namespace App\Enums;

enum GatePassStatus: string
{
    case Draft = 'draft';
    case PendingVerification = 'pending_verification';
    case PartiallyAccepted = 'partially_accepted';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
