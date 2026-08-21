<?php

namespace App\Notifications;

use App\Models\Sale;
use App\Services\CustomerCreditService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class OverdueCreditInvoiceNotification extends Notification
{
    use Queueable;

    public function __construct(private Sale $sale, private array $invoice)
    {
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        $customer = optional($this->sale->client)->name ?: 'Customer';
        $amount = number_format($this->invoice['outstanding_amount'], 2);

        return [
            'type' => 'overdue_credit_invoice',
            'title' => 'Overdue Credit Invoice',
            'sale_id' => (int) $this->sale->id,
            'reference' => (string) $this->sale->Ref,
            'customer_name' => $customer,
            'invoice_date' => $this->invoice['invoice_date'],
            'credit_due_date' => $this->invoice['credit_due_date'],
            'original_amount' => $this->invoice['original_amount'],
            'paid_amount' => $this->invoice['paid_amount'],
            'outstanding_amount' => $this->invoice['outstanding_amount'],
            'days_overdue' => $this->invoice['days_overdue'],
            'warehouse_name' => optional($this->sale->warehouse)->name,
            'message' => "Invoice {$this->sale->Ref} for {$customer} is overdue. Outstanding amount: PKR {$amount}. Due date: {$this->invoice['credit_due_date']}.",
            'url' => '/app/sales/detail/'.$this->sale->id,
        ];
    }
}
