<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
{
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'date', 'Ref', 'user_id', 'sales_tax_invoice_no', 'delivery_note_no', 'purchase_order_id', 'gate_pass_id',
        'supplier_invoice_id', 'invoice_tax_type', 'inventory_already_received', 'posting_status',
        'provider_id', 'warehouse_id', 'GrandTotal', 'time',
        'discount', 'shipping', 'statut', 'notes', 'TaxNet', 'withholding_tax', 'tax_rate', 'paid_amount',
        'payment_statut', 'created_at', 'updated_at', 'deleted_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'provider_id' => 'integer',
        'warehouse_id' => 'integer',
        'GrandTotal' => 'double',
        'discount' => 'double',
        'shipping' => 'double',
        'TaxNet' => 'double',
        'withholding_tax' => 'double',
        'tax_rate' => 'double',
        'paid_amount' => 'double',
        'inventory_already_received' => 'boolean',
    ];

    public function details()
    {
        return $this->hasMany('App\Models\PurchaseDetail');
    }

    public function provider()
    {
        return $this->belongsTo('App\Models\Provider');
    }

    public function facture()
    {
        return $this->hasMany('App\Models\PaymentPurchase');
    }

    public function warehouse()
    {
        return $this->belongsTo('App\Models\Warehouse');
    }

    public function user()
    {
        return $this->belongsTo('App\Models\User');
    }

    public function documents()
    {
        return $this->hasMany('App\Models\PurchaseDocument', 'purchase_id');
    }

    public function purchaseOrder() { return $this->belongsTo(PurchaseOrder::class); }
    public function gatePass() { return $this->belongsTo(GatePass::class); }
    public function gatePasses() { return $this->belongsToMany(GatePass::class, 'purchase_gate_pass')->withTimestamps(); }
    public function supplierInvoice() { return $this->belongsTo(SupplierInvoice::class); }
}
