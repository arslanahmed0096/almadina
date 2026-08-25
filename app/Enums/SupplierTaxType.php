<?php

namespace App\Enums;

enum SupplierTaxType: string
{
    case Gst = 'gst';
    case NonGst = 'non_gst';
}
