<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ProcurementWorkflowNotification extends Notification
{
    use Queueable;

    public function __construct(protected string $action, protected string $message, protected string $reference, protected string $url) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return ['type' => 'procurement', 'action' => $this->action, 'message' => $this->message, 'reference' => $this->reference, 'url' => $this->url];
    }
}
