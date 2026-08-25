<?php

namespace App\Enums;

enum SupplierInvoiceStatus: string
{
    case Draft = 'draft';
    case Recorded = 'recorded';
    case Posted = 'posted';
    case Cancelled = 'cancelled';
}
