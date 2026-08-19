<?php

namespace App\Notifications;

use App\Models\Transfer;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TransferWorkflowNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected Transfer $transfer,
        protected string $action,
        protected string $message
    ) {
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'stock_transfer',
            'transfer_id' => (int) $this->transfer->id,
            'reference' => (string) $this->transfer->Ref,
            'action' => $this->action,
            'status' => (string) $this->transfer->workflow_status,
            'message' => $this->message,
            'url' => '/app/transfers/detail/' . $this->transfer->id,
        ];
    }
}
