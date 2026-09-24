<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Marketplace\Models\LabelBatch;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The agency has uploaded the day's labels for a brand: the depot can
 * print. Rung on the bell and sent by email, so it does not wait for
 * someone to open the ERP.
 */
class LabelsReadyNotification extends Notification
{
    public function __construct(
        public readonly LabelBatch $batch,
        public readonly int $parcels,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = (string) config('app.name');

        return (new MailMessage)
            ->subject("{$this->parcels} {$this->batch->marketplace->name} labels to print — {$this->batch->brand->name}")
            ->greeting('Labels are in.')
            ->line("{$this->parcels} {$this->batch->brand->name} parcel(s) on {$this->batch->marketplace->name} are ready to print at {$this->batch->facility->name} ({$this->batch->number}).")
            ->line('Print them grouped by courier; each parcel is packed by scanning its label.')
            ->action('Open the labels', route('online-orders.show', $this->batch))
            ->salutation("— {$app}");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => "{$this->parcels} {$this->batch->marketplace->name} labels to print",
            'body' => "{$this->batch->brand->name} · {$this->batch->facility->name} · {$this->batch->number}",
            'href' => route('online-orders.show', $this->batch),
            'severity' => 'medium',
            'category' => 'online_orders',
            'key' => "labels:{$this->batch->id}",
        ];
    }
}
