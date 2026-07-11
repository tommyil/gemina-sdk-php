<?php

declare(strict_types=1);

namespace Gemina\Sdk\Tests;

use Gemina\Sdk\Api\ChatApi;
use Gemina\Sdk\GeminaChatConversation;
use Gemina\Sdk\GeminaClient;
use Gemina\Sdk\Model\ChatQueryInDTO;
use Gemina\Sdk\Model\ChatQueryOutDTO;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GeminaChatConversationTest extends TestCase
{
    // ------------------------------------------------------------------
    // Factory
    // ------------------------------------------------------------------

    public function testConversationFactoryReturnsHelperWithNoSessionYet(): void
    {
        [, $convo] = $this->makeConversation();

        self::assertInstanceOf(GeminaChatConversation::class, $convo);
        self::assertNull($convo->getSessionId());
    }

    // ------------------------------------------------------------------
    // Threading the session id across turns
    // ------------------------------------------------------------------

    public function testOmitsSessionIdOnFirstTurnAndThreadsItOnEveryFollowingTurn(): void
    {
        [$chat, $convo] = $this->makeConversation('eu-1');

        /** @var list<ChatQueryInDTO> $sent */
        $sent = [];
        $chat->expects(self::exactly(2))
            ->method('chatQuery')
            ->with(self::callback(static function (ChatQueryInDTO $dto) use (&$sent): bool {
                $sent[] = $dto;

                return true;
            }))
            ->willReturnOnConsecutiveCalls(
                $this->reply('first', 'sess-9'),
                $this->reply('second', 'sess-9'),
            );

        $first = $convo->send('turn one');
        self::assertSame('first', $first->getAnswer());
        self::assertSame('sess-9', $convo->getSessionId());

        $convo->send('turn two');

        // First turn: message + endUserId, NO sessionId.
        self::assertSame('turn one', $sent[0]->getMessage());
        self::assertSame('eu-1', $sent[0]->getEndUserId());
        self::assertNull($sent[0]->getSessionId());

        // Second turn: the server-issued sessionId is threaded back.
        self::assertSame('turn two', $sent[1]->getMessage());
        self::assertSame('eu-1', $sent[1]->getEndUserId());
        self::assertSame('sess-9', $sent[1]->getSessionId());
    }

    // ------------------------------------------------------------------
    // reset()
    // ------------------------------------------------------------------

    public function testResetForgetsTheSessionSoTheNextTurnStartsFresh(): void
    {
        [$chat, $convo] = $this->makeConversation();

        /** @var list<ChatQueryInDTO> $sent */
        $sent = [];
        $chat->expects(self::exactly(2))
            ->method('chatQuery')
            ->with(self::callback(static function (ChatQueryInDTO $dto) use (&$sent): bool {
                $sent[] = $dto;

                return true;
            }))
            ->willReturnOnConsecutiveCalls(
                $this->reply('a', 'sess-a'),
                $this->reply('b', 'sess-b'),
            );

        $convo->send('one');
        self::assertSame('sess-a', $convo->getSessionId());

        $convo->reset();
        self::assertNull($convo->getSessionId());

        $convo->send('two');
        // No sessionId carried after a reset — a brand-new conversation.
        self::assertNull($sent[1]->getSessionId());
        self::assertSame('sess-b', $convo->getSessionId());
    }

    // ------------------------------------------------------------------
    // delete()
    // ------------------------------------------------------------------

    public function testDeleteRemovesTheSessionServerSideAndForgetsItLocally(): void
    {
        [$chat, $convo] = $this->makeConversation();
        $chat->method('chatQuery')->willReturn($this->reply('x', 'sess-x'));

        $chat->expects(self::once())
            ->method('deleteChatSession')
            ->with('sess-x');

        $convo->send('hi');
        $convo->delete();

        self::assertNull($convo->getSessionId());
    }

    public function testDeleteIsANoOpBeforeAnyTurn(): void
    {
        [$chat, $convo] = $this->makeConversation();

        $chat->expects(self::never())->method('deleteChatSession');

        $convo->delete();
        self::assertNull($convo->getSessionId());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @return array{0: ChatApi&MockObject, 1: GeminaChatConversation}
     */
    private function makeConversation(?string $endUserId = null): array
    {
        $chat = $this->createMock(ChatApi::class);
        $client = new GeminaClient('test-api-key', 'https://api.example.invalid', [
            'apis' => ['chat' => $chat],
        ]);
        $convo = $client->conversation($endUserId !== null ? ['endUserId' => $endUserId] : []);

        return [$chat, $convo];
    }

    private function reply(string $answer, string $sessionId): ChatQueryOutDTO
    {
        return new ChatQueryOutDTO([
            'answer' => $answer,
            'session_id' => $sessionId,
        ]);
    }
}
