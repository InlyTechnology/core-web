<?php

namespace Inly\CoreWeb\Content;

use Inly\CoreWeb\Client\CoreClient;
use InvalidArgumentException;
use RuntimeException;

/**
 * Tells Core a page changed: `POST {core}/webhooks/connectors/{connectorId}`
 * with the site's client token, `{type: "page", id, action, nonce}`. Core
 * answers 202 and reads the page back, so the ping carries only its id.
 */
class ContentPings
{
    public const ACTIONS = ['saved', 'deleted'];

    public function __construct(protected CoreClient $client, protected string $connectorId) {}

    public static function fromConfig(): static
    {
        return new static(CoreClient::fromConfig(), (string) config('core-web.content.connector_id'));
    }

    /** Whether there is a Core to ping: the client is configured and the connector that holds the pairing is named. */
    public function enabled(): bool
    {
        return $this->client->configured() && $this->connectorId !== '';
    }

    public function path(): string
    {
        return '/webhooks/connectors/'.rawurlencode($this->connectorId);
    }

    /** @throws RuntimeException when Core does not accept the ping, for the caller's queue to retry */
    public function send(string $id, string $action): void
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException("A ping's action is saved or deleted, not [{$action}].");
        }

        if ($id === '') {
            throw new InvalidArgumentException('A ping names the page that changed.');
        }

        if (! $this->enabled()) {
            throw new RuntimeException('core-web cannot ping Core: the client or core-web.content.connector_id is not set.');
        }

        // A fresh nonce for every attempt, the token retry included.
        $response = $this->client->sendResponse('post', $this->path(), fn (): array => [
            'type' => 'page',
            'id' => $id,
            'action' => $action,
            'nonce' => bin2hex(random_bytes(16)),
        ], timeout: 10);

        if (! $response->successful()) {
            throw new RuntimeException("Core refused the ping for page {$id} [{$response->status()}]: ".mb_strimwidth($response->body(), 0, 200, '…'));
        }
    }
}
