<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

class NotificationsChanged implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    public int $tries = 5;

    private readonly string $eventId;

    public function __construct(public int $userId, public ?string $notificationId = null)
    {
        $this->eventId = (string) Str::uuid();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('users.'.$this->userId.'.notifications')];
    }

    public function broadcastAs(): string
    {
        return 'notifications.changed';
    }

    public function broadcastWhen(): bool
    {
        return (bool) config('notifications.broadcast_enabled');
    }

    public function shouldBroadcastNow(): bool
    {
        return (bool) config('notifications.broadcast_sync');
    }

    /** @return array{notification_id: ?string, event_id: string} */
    public function broadcastWith(): array
    {
        return ['notification_id' => $this->notificationId, 'event_id' => $this->eventId];
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [1, 5, 15, 30];
    }
}
