<?php

namespace App\Services\Realtime;

use App\Models\RealtimeOutboxModel;
use Config\Pusher as PusherConfig;
use Pusher\Pusher;
use Throwable;

class RealtimeService
{
    public function __construct(
        private readonly ?RealtimeOutboxModel $outbox = null,
        private readonly ?PusherConfig $config = null
    ) {
    }

    public function publish(int $roomId, string $channel, string $event, array $payload): void
    {
        $outbox = $this->outbox ?? new RealtimeOutboxModel();
        $config = $this->config ?? config(PusherConfig::class);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $outboxId = $outbox->insert([
            'room_id' => $roomId,
            'channel' => $channel,
            'event' => $event,
            'payload_json' => $payloadJson,
            'status' => 'PENDING',
            'attempts' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ], true);

        if (! $config->isConfigured()) {
            return;
        }

        try {
            $pusher = new Pusher($config->key, $config->secret, $config->appId, [
                'cluster' => $config->cluster,
                'useTLS' => $config->useTLS,
            ]);
            $pusher->trigger($channel, $event, $payload);

            $outbox->update($outboxId, [
                'status' => 'SENT',
                'attempts' => 1,
                'sent_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $error) {
            $outbox->update($outboxId, [
                'status' => 'FAILED',
                'attempts' => 1,
                'last_error' => $error->getMessage(),
            ]);
        }
    }
}
