<?php

declare(strict_types=1);

namespace App\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The password reset email, in the ERP's own words rather than the
 * framework's. It says when and from where the request came, so an
 * employee who did not ask for it can tell at a glance, and it says
 * plainly that ignoring it changes nothing.
 *
 * Sent at once rather than queued: nothing else in the ERP runs through a
 * queue yet, and a reset email waiting on a worker that was never started
 * would fail without anyone knowing.
 */
class ResetPasswordNotification extends ResetPassword
{
    public function __construct(
        #[\SensitiveParameter] string $token,
        public readonly ?string $requestedFromIp = null,
        public readonly ?string $requestedFromDevice = null,
        public readonly ?CarbonImmutable $requestedAt = null,
    ) {
        parent::__construct($token);
    }

    /**
     * Build the message from what the request that triggered it knew.
     */
    public static function forCurrentRequest(string $token): self
    {
        $request = request();

        return new self(
            $token,
            requestedFromIp: $request?->ip(),
            requestedFromDevice: self::describeDevice((string) $request?->userAgent()),
            requestedAt: CarbonImmutable::now(),
        );
    }

    /**
     * @param  mixed  $notifiable
     */
    public function toMail($notifiable): MailMessage
    {
        $app = (string) config('app.name');
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);
        $when = ($this->requestedAt ?? CarbonImmutable::now())
            ->setTimezone((string) config('erp.company.timezone', 'UTC'))
            ->format('j M Y, g:i A T');

        $where = collect([$this->requestedFromDevice, $this->requestedFromIp ? "IP address {$this->requestedFromIp}" : null])
            ->filter()
            ->implode(', ');

        return (new MailMessage)
            ->subject("Reset your {$app} password")
            ->greeting('Hello '.self::firstName((string) ($notifiable->name ?? '')).',')
            ->line("Someone asked to reset the password for your {$app} account, {$notifiable->email}.")
            ->line('Requested on '.$when.($where !== '' ? " from {$where}." : '.'))
            ->action('Choose a new password', $this->resetUrl($notifiable))
            ->line("This link works once, for the next {$minutes} minutes.")
            ->line('If you did not ask for this, ignore this email. Your password has not changed, and nobody can change it without this link.')
            ->line('Accounts are created by your administrator. If you think this one should not exist, tell them.')
            ->salutation("— {$app}");
    }

    private static function firstName(string $name): string
    {
        $first = trim(explode(' ', trim($name))[0] ?? '');

        return $first !== '' ? $first : 'there';
    }

    /**
     * "Chrome on Windows" from a user-agent string: enough for a person to
     * recognise their own request, without a parsing library for one line.
     */
    public static function describeDevice(string $userAgent): ?string
    {
        if (trim($userAgent) === '') {
            return null;
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') || str_contains($userAgent, 'CriOS/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };

        $system = match (true) {
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS X') || str_contains($userAgent, 'Macintosh') => 'Mac',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return match (true) {
            $browser !== null && $system !== null => "{$browser} on {$system}",
            $browser !== null => $browser,
            $system !== null => "a browser on {$system}",
            default => null,
        };
    }
}
