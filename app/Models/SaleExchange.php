<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleExchange extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'original_sale_id' => 'integer',
        'sale_return_id' => 'integer',
        'replacement_sale_id' => 'integer',
        'client_id' => 'integer',
        'warehouse_id' => 'integer',
        'created_by' => 'integer',
    ];

    public function originalSale()
    {
        return $this->belongsTo(Sale::class, 'original_sale_id');
    }

    public function saleReturn()
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function replacementSale()
    {
        return $this->belongsTo(Sale::class, 'replacement_sale_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function availableCredit(): float
    {
        return $this->creditAfterPayment();
    }

    public function creditAfterPayment(float $paymentAllocatedToOriginalSale = 0): float
    {
        $return = $this->saleReturn;
        $originalSale = $this->originalSale;

        if (! $return || ! $originalSale) {
            return 0.0;
        }

        $unrefundedReturnValue = $this->returnValue();
        $customerPaidOnOriginalSale = max(
            0,
            round((float) $originalSale->paid_amount + max(0, $paymentAllocatedToOriginalSale), 2)
        );

        return min($unrefundedReturnValue, $customerPaidOnOriginalSale);
    }

    public function returnValue(): float
    {
        $return = $this->saleReturn;

        return $return
            ? max(0, round((float) $return->GrandTotal - (float) $return->paid_amount, 2))
            : 0.0;
    }
}
