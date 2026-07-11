<?php

declare(strict_types=1);

namespace Gemina\Sdk;

use Gemina\Sdk\Api\ChatApi;
use Gemina\Sdk\Model\ChatQueryInDTO;
use Gemina\Sdk\Model\ChatQueryOutDTO;

/**
 * A stateful chat conversation that threads the server-issued sessionId across
 * turns, so follow-up questions keep context — you never touch the id yourself.
 *
 *     $chat = $client->conversation();
 *     $chat->send('How much did I spend at Acme in Q1?');
 *     $chat->send('And the biggest invoice?'); // remembers Acme / Q1
 *     $chat->delete();                         // end it server-side
 *
 * A turn that carries a stale session (24h idle TTL, or after reset()) fails
 * with the API's 404 CHAT_SESSION_NOT_FOUND; catch it, call reset(), and
 * resend to continue in a fresh conversation. It does NOT auto-retry.
 *
 * Create one via GeminaClient::conversation(); the full one-shot surface stays
 * available on GeminaClient::chat().
 */
final class GeminaChatConversation
{
    private ?string $currentSessionId = null;

    /**
     * @param ChatApi     $chat      Shared generated Chat API group.
     * @param string|null $endUserId End-user id forwarded with each turn (API
     *        key path only; on the session-token path the token's signed scope
     *        wins server-side).
     */
    public function __construct(
        private readonly ChatApi $chat,
        private readonly ?string $endUserId = null,
    ) {
    }

    /**
     * The current conversation id — null before the first turn or after a
     * reset().
     */
    public function getSessionId(): ?string
    {
        return $this->currentSessionId;
    }

    /**
     * Send one turn; its answer continues this conversation. The session id is
     * omitted on the first turn and threaded automatically on every turn after.
     *
     * @param string $message The user message (1–2000 chars).
     *
     * @throws \Gemina\Sdk\ApiException Transport/HTTP errors pass through
     *         unwrapped — including the 404 CHAT_SESSION_NOT_FOUND raised when
     *         the session has expired (reset() and resend to recover).
     */
    public function send(string $message): ChatQueryOutDTO
    {
        $data = [
            'message' => $message,
            'end_user_id' => $this->endUserId,
        ];
        if ($this->currentSessionId !== null) {
            $data['session_id'] = $this->currentSessionId;
        }

        $result = $this->chat->chatQuery(new ChatQueryInDTO($data));

        if ($result instanceof ChatQueryOutDTO && $result->getSessionId() !== null) {
            $this->currentSessionId = $result->getSessionId();
        }

        return $result;
    }

    /**
     * Forget the conversation locally; the next send() starts a new one.
     */
    public function reset(): void
    {
        $this->currentSessionId = null;
    }

    /**
     * End the conversation: delete it server-side (mirrors a "New chat" action)
     * and forget it locally. No-op if no turn has been sent yet.
     *
     * @throws \Gemina\Sdk\ApiException Transport/HTTP errors pass through unwrapped.
     */
    public function delete(): void
    {
        $sessionId = $this->currentSessionId;
        $this->currentSessionId = null;
        if ($sessionId !== null) {
            $this->chat->deleteChatSession($sessionId);
        }
    }
}
