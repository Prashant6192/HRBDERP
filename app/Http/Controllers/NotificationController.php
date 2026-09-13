<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the ERP has told this person: escalations, approvals waiting on
 * them, exceptions in their area. Theirs alone; nobody reads another's.
 */
class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $notifications = $request->user()->notifications()
            ->latest()
            ->paginate(25)
            ->through(fn (DatabaseNotification $n) => self::present($n));

        return Inertia::render('notifications/index', [
            'notifications' => $notifications,
            'unread' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function read(Request $request, string $notification): RedirectResponse
    {
        $found = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $found->markAsRead();

        $href = $found->data['href'] ?? null;

        return $href && $request->boolean('open') ? redirect()->to($href) : back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'All notifications marked as read.']);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(DatabaseNotification $n): array
    {
        return [
            'id' => $n->id,
            'title' => $n->data['title'] ?? '',
            'body' => $n->data['body'] ?? '',
            'href' => $n->data['href'] ?? null,
            'severity' => $n->data['severity'] ?? 'medium',
            'category' => $n->data['category'] ?? 'exception',
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }
}
