<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Something the ERP wants a person to know: an escalation, an approval
 * waiting on them, an exception in their area. Stored in the database and
 * shown under the bell; other channels can be added without touching the
 * business logic that raised it.
 */
class ErpAlert extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $href = null,
        public readonly string $severity = 'medium',
        public readonly string $category = 'exception',
        public readonly ?string $key = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'href' => $this->href,
            'severity' => $this->severity,
            'category' => $this->category,
            'key' => $this->key,
        ];
    }
}
