<?php

namespace App\Enums;

enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case PartiallyReceived = 'partially_received';
    case FullyReceived = 'fully_received';
    case PartiallyInvoiced = 'partially_invoiced';
    case FullyInvoiced = 'fully_invoiced';
    case PartiallyPurchased = 'partially_purchased';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
