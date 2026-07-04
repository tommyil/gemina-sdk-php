<?php

declare(strict_types=1);

namespace Gemina\Sdk\Tests;

use Gemina\Sdk\Api\DocumentApi;
use Gemina\Sdk\ApiException;
use Gemina\Sdk\GeminaClient;
use Gemina\Sdk\GeminaException;
use Gemina\Sdk\GeminaProcessingException;
use Gemina\Sdk\GeminaTimeoutException;
use Gemina\Sdk\Model\DocumentProcessingMetaOutDTO;
use Gemina\Sdk\Model\DocumentProcessingResultOutDTO;
use Gemina\Sdk\Model\WebDocumentUploadInDTO;
use Gemina\Sdk\SdkVersion;
use Gemina\Sdk\UrlSource;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GeminaClientTest extends TestCase
{
    private const CORRELATION_ID = 'corr-123';

    /** @var list<float> */
    private array $sleeps = [];

    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $httpHistory = [];

    private string $tempFile = '';

    protected function tearDown(): void
    {
        if ($this->tempFile !== '' && is_file($this->tempFile)) {
            unlink($this->tempFile);
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Construction / auth wiring
    // ------------------------------------------------------------------

    public function testConstructorWiresApiKeyHostAndUserAgent(): void
    {
        $client = new GeminaClient('test-key', 'https://api.example.test/');
        $config = $client->getConfiguration();

        self::assertSame('test-key', $config->getApiKey('X-API-Key'));
        self::assertSame('https://api.example.test', $config->getHost());
        self::assertSame('gemina-sdk-php/' . SdkVersion::VERSION, $config->getUserAgent());
        self::assertSame('', $config->getAccessToken());
    }

    public function testWithSessionTokenUsesBearerInsteadOfApiKey(): void
    {
        $client = GeminaClient::withSessionToken('session-token', 'https://api.example.test');
        $config = $client->getConfiguration();

        self::assertSame('session-token', $config->getAccessToken());
        self::assertNull($config->getApiKey('X-API-Key'));
    }

    // ------------------------------------------------------------------
    // Contract §3.1 — happy path (file submit → 2 non-terminal polls → success)
    // ------------------------------------------------------------------

    public function testHappyPathPollsUntilSuccess(): void
    {
        $documents = $this->mockDocuments();
        $documents->expects(self::exactly(3))
            ->method('getDocumentProcessingResultByCorrelationId')
            ->with(self::CORRELATION_ID)
            ->willReturnOnConsecutiveCalls(
                $this->processingResult('pending'),
                $this->processingResult('in_process'),
                $this->processingResult('success'),
            );

        $client = $this->client($documents, submitResponses: [
            new Response(202, ['Content-Type' => 'application/json'], $this->resultJson('pending')),
        ]);

        $result = $client->processDocument(
            $this->tempInvoicePath(),
            ['invoice_headers', 'invoice_line_items'],
        );

        self::assertSame('success', (string) $result->getStatus());
        self::assertCount(3, $this->sleeps);

        // Exactly one HTTP submit; polling went through the generated API.
        self::assertCount(1, $this->httpHistory);
        $request = $this->httpHistory[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            'https://api.example.invalid/api/v1/documents/requests',
            (string) $request->getUri(),
        );
        self::assertSame('test-api-key', $request->getHeaderLine('X-API-Key'));
        self::assertSame('gemina-sdk-php/' . SdkVersion::VERSION, $request->getHeaderLine('User-Agent'));

        // Repeated bare "extraction_types" parts — NOT bracket notation
        // (the generated FormDataProcessor would emit extraction_types[0],
        // which the API rejects; this guards the hand-rolled submit).
        $body = (string) $request->getBody();
        self::assertSame(2, substr_count($body, 'name="extraction_types"'));
        self::assertStringNotContainsString('extraction_types[', $body);
        self::assertStringContainsString('invoice_headers', $body);
        self::assertStringContainsString('invoice_line_items', $body);
        self::assertStringContainsString('name="external_id"', $body);
        self::assertMatchesRegularExpression('/name="file"; filename="[^"]+\.png"/', $body);
        self::assertStringContainsString('fake-image-bytes', $body);
    }

    public function testTerminalFileSubmitResponseSkipsPolling(): void
    {
        $documents = $this->mockDocuments();
        $documents->expects(self::never())
            ->method('getDocumentProcessingResultByCorrelationId');

        $client = $this->client($documents, submitResponses: [
            new Response(200, ['Content-Type' => 'application/json'], $this->resultJson('success')),
        ]);

        $result = $client->processDocument($this->tempInvoicePath(), ['invoice_headers']);

        self::assertSame('success', (string) $result->getStatus());
        self::assertSame(self::CORRELATION_ID, $result->getMeta()->getCorrelationId());
        self::assertSame([], $this->sleeps);
    }

    // ------------------------------------------------------------------
    // Contract §3.2 — terminal "failed"
    // ------------------------------------------------------------------

    public function testFailedStatusThrowsProcessingExceptionCarryingResult(): void
    {
        $failed = $this->processingResult('failed');

        $documents = $this->mockDocuments();
        $documents->method('createWebDocumentProcessingRequest')
            ->willReturn($this->processingResult('pending'));
        $documents->method('getDocumentProcessingResultByCorrelationId')
            ->willReturn($failed);

        $client = $this->client($documents);

        try {
            $client->processDocument($this->urlSource(), ['invoice_headers']);
            self::fail('Expected GeminaProcessingException');
        } catch (GeminaProcessingException $e) {
            self::assertSame($failed, $e->getResult());
        }
    }

    // ------------------------------------------------------------------
    // Terminal "failed" reported as HTTP 500 (body IS the failed result)
    // ------------------------------------------------------------------

    public function testPollHttp500WithFailedResultBodyThrowsProcessingException(): void
    {
        $failedBody = json_encode([
            'status' => 'failed',
            'errors' => [['error' => 'Document could not be processed']],
            'meta' => ['correlationId' => self::CORRELATION_ID],
        ], JSON_THROW_ON_ERROR);

        $documents = $this->mockDocuments();
        $documents->method('createWebDocumentProcessingRequest')
            ->willReturn($this->processingResult('pending'));
        $documents->expects(self::once())
            ->method('getDocumentProcessingResultByCorrelationId')
            ->willThrowException(new ApiException('[500] Server error', 500, [], $failedBody));

        $client = $this->client($documents);

        try {
            $client->processDocument($this->urlSource(), ['invoice_headers']);
            self::fail('Expected GeminaProcessingException');
        } catch (GeminaProcessingException $e) {
            self::assertSame('failed', (string) $e->getResult()->getStatus());
            self::assertNotEmpty($e->getResult()->getErrors());
            self::assertSame(
                self::CORRELATION_ID,
                $e->getResult()->getMeta()->getCorrelationId(),
            );
        }

        // Failed-result body is terminal — no transient retry happened.
        self::assertCount(1, $this->sleeps);
    }

    // ------------------------------------------------------------------
    // Transient poll failures are retried (contract §2 step 3)
    // ------------------------------------------------------------------

    public function testTransientPollErrorsAreRetriedThenSucceed(): void
    {
        $documents = $this->mockDocuments();
        $documents->method('createWebDocumentProcessingRequest')
            ->willReturn($this->processingResult('pending'));

        $polls = 0;
        $documents->expects(self::exactly(3))
            ->method('getDocumentProcessingResultByCorrelationId')
            ->willReturnCallback(function () use (&$polls): DocumentProcessingResultOutDTO {
                $polls++;
                if ($polls <= 2) {
                    throw new ApiException('[503] Service Unavailable', 503, [], 'upstream connect error');
                }

                return $this->processingResult('success');
            });

        $client = $this->client($documents);
        $result = $client->processDocument($this->urlSource(), ['invoice_headers']);

        self::assertSame('success', (string) $result->getStatus());
        // Backoff schedule continued across the transient failures.
        self::assertEqualsWithDelta([2.0, 3.0, 4.5], $this->sleeps, 1e-9);
    }

    public function testThreeConsecutiveTransientPollErrorsRethrowTheLastOneUnchanged(): void
    {
        $errors = [
            new ApiException('[503] blip 1', 503, [], 'upstream connect error'),
            new ApiException('[502] blip 2', 502, [], ''),
            new ApiException('[503] blip 3', 503, [], json_encode(['detail' => 'boom'], JSON_THROW_ON_ERROR)),
        ];

        $documents = $this->mockDocuments();
        $documents->method('createWebDocumentProcessingRequest')
            ->willReturn($this->processingResult('pending'));

        $polls = 0;
        $documents->expects(self::exactly(3))
            ->method('getDocumentProcessingResultByCorrelationId')
            ->willReturnCallback(static function () use (&$polls, $errors): DocumentProcessingResultOutDTO {
                throw $errors[$polls++];
            });

        $client = $this->client($documents);

        try {
            $client->processDocument($this->urlSource(), ['invoice_headers']);
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame($errors[2], $e);
        }

        self::assertCount(3, $this->sleeps);
    }

    public function testSuccessfulPollResetsTheTransientFailureCounter(): void
    {
        // fail, fail, pending (resets the counter), fail, fail, success —
        // four transient failures total but never three consecutive.
        $script = ['error', 'error', 'pending', 'error', 'error', 'success'];

        $documents = $this->mockDocuments();
        $documents->method('createWebDocumentProcessingRequest')
            ->willReturn($this->processingResult('pending'));

        $polls = 0;
        $documents->expects(self::exactly(6))
            ->method('getDocumentProcessingResultByCorrelationId')
            ->willReturnCallback(function () use (&$polls, $script): DocumentProcessingResultOutDTO {
                $step = $script[$polls++];
                if ($step === 'error') {
                    throw new ApiException('[503] Service Unavailable', 503, [], 'upstream connect error');
                }

                return $this->processingResult($step);
            });

        $client = $this->client($documents);
        $result = $client->processDocument($this->urlSource(), ['invoice_headers']);

        self::assertSame('success', (string) $result->getStatus());
        self::assertCount(6, $this->sleeps);
    }

    public function testFileSubmitHttp500WithFailedResultBodyThrowsProcessingException(): void
    {
        $failedBody = json_encode([
            'status' => 'failed',
            'errors' => [['error' => 'Unsupported document']],
            'meta' => ['correlationId' => self::CORRELATION_ID],
        ], JSON_THROW_ON_ERROR);

        $documents = $this->mockDocuments();
        $documents->expects(self::never())
            ->method('getDocumentProcessingResultByCorrelationId');

        $client = $this->client($documents, submitResponses: [
            new Response(500, ['Content-Type' => 'application/json'], $failedBody),
        ]);

        try {
            $client->processDocument($this->tempInvoicePath(), ['invoice_headers']);
            self::fail('Expected GeminaProcessingException');
        } catch (GeminaProcessingException $e) {
            self::assertSame('failed', (string) $e->getResult()->getStatus());
            self::assertNotEmpty($e->getResult()->getErrors());
        }
    }

    // ------------------------------------------------------------------
    // Contract §3.3 — timeout
    // ------------------------------------------------------------------

    public function testTimeoutThrowsTimeoutExceptionWithCorrelationIdAndLastResult(): void
    {
        $documents = $this->mockDocuments();
        $documents->method('createWebDocumentProcessingRequest')
            ->willReturn($this->processingResult('pending'));
        $documents->method('getDocumentProcessingResultByCorrelationId')
            ->willReturn($this->processingResult('in_process'));

        $client = $this->client($documents);

        try {
            $client->processDocument(
                $this->urlSource(),
                ['invoice_headers'],
                ['timeoutSeconds' => 10.0],
            );
            self::fail('Expected GeminaTimeoutException');
        } catch (GeminaTimeoutException $e) {
            self::assertSame(self::CORRELATION_ID, $e->getCorrelationId());
            self::assertNotNull($e->getLastResult());
            self::assertSame('in_process', (string) $e->getLastResult()->getStatus());
        }

        // jitter = 1.0 → 2.0 + 3.0 + 4.5 + 6.75 = 16.25 elapsed >= 10 after the 4th wait
        self::assertEqualsWithDelta([2.0, 3.0, 4.5, 6.75], $this->sleeps, 1e-9);
    }

    // ------------------------------------------------------------------
    // Contract §3.4 — backoff schedule
    // ------------------------------------------------------------------

    public function testBackoffGrowsByOneAndAHalfCappedAtMaxInterval(): void
    {
        $documents = $this->mockDocuments();
        $documents->method('createWebDocumentProcessingRequest')
            ->willReturn($this->processingResult('pending'));
        $documents->method('getDocumentProcessingResultByCorrelationId')
            ->willReturnOnConsecutiveCalls(
                $this->processingResult('pending'),
                $this->processingResult('pending'),
                $this->processingResult('pending'),
                $this->processingResult('pending'),
                $this->processingResult('pending'),
                $this->processingResult('pending'),
                $this->processingResult('success'),
            );

        $client = $this->client($documents); // random = 0.5 → jitter exactly 1.0
        $client->processDocument(
            $this->urlSource(),
            ['invoice_headers'],
            ['timeoutSeconds' => 1000.0],
        );

        // 2.0 ×1.5 per attempt, nominal capped at 15.0
        self::assertEqualsWithDelta(
            [2.0, 3.0, 4.5, 6.75, 10.125, 15.0, 15.0],
            $this->sleeps,
            1e-9,
        );
    }

    public function testJitterBoundsAreAppliedToTheNominalWait(): void
    {
        // random() = 0.0 → jitter factor is exactly 0.8 (lower bound)
        $documents = $this->mockDocuments();
        $documents->method('createWebDocumentProcessingRequest')
            ->willReturn($this->processingResult('pending'));
        $documents->method('getDocumentProcessingResultByCorrelationId')
            ->willReturnOnConsecutiveCalls(
                $this->processingResult('pending'),
                $this->processingResult('pending'),
                $this->processingResult('success'),
            );

        $client = $this->client($documents, randomValue: 0.0);
        $client->processDocument($this->urlSource(), ['invoice_headers']);
        self::assertEqualsWithDelta([1.6, 2.4, 3.6], $this->sleeps, 1e-9);

        // random() = 1.0 → jitter factor is exactly 1.2 (upper bound)
        $documents = $this->mockDocuments();
        $documents->method('createWebDocumentProcessingRequest')
            ->willReturn($this->processingResult('pending'));
        $documents->method('getDocumentProcessingResultByCorrelationId')
            ->willReturn($this->processingResult('success'));

        $client = $this->client($documents, randomValue: 1.0);
        $client->processDocument($this->urlSource(), ['invoice_headers']);
        self::assertEqualsWithDelta([2.4], $this->sleeps, 1e-9);

        // Every wait stays within [0.8, 1.2] × nominal
        foreach ([[1.6, 2.0], [2.4, 2.0]] as [$actual, $nominal]) {
            self::assertGreaterThanOrEqual(0.8 * $nominal, $actual);
            self::assertLessThanOrEqual(1.2 * $nominal, $actual);
        }
    }

    // ------------------------------------------------------------------
    // Contract §3.5 — URL source routes to the web endpoint
    // ------------------------------------------------------------------

    public function testUrlSourceRoutesToWebEndpoint(): void
    {
        $documents = $this->mockDocuments();
        $documents->expects(self::once())
            ->method('createWebDocumentProcessingRequest')
            ->with(self::callback(static function (WebDocumentUploadInDTO $dto): bool {
                return $dto->getUrl() === 'https://example.com/invoice.pdf'
                    && $dto->getExtractionTypes() === ['invoice_headers']
                    && is_string($dto->getExternalId())
                    && $dto->getExternalId() !== '';
            }))
            ->willReturn($this->processingResult('success'));

        $client = $this->client($documents);
        $result = $client->processDocument(
            new UrlSource('https://example.com/invoice.pdf'),
            ['invoice_headers'],
        );

        self::assertSame('success', (string) $result->getStatus());
        // No hand-rolled multipart submit happened.
        self::assertSame([], $this->httpHistory);
    }

    // ------------------------------------------------------------------
    // Malformed responses / bad input
    // ------------------------------------------------------------------

    public function testNonTerminalSubmitWithoutCorrelationIdThrowsGeminaException(): void
    {
        $documents = $this->mockDocuments();
        $documents->method('createWebDocumentProcessingRequest')
            ->willReturn($this->processingResult('pending', correlationId: null));

        $client = $this->client($documents);

        $this->expectException(GeminaException::class);
        $this->expectExceptionMessage('correlationId');
        $client->processDocument($this->urlSource(), ['invoice_headers']);
    }

    public function testEmptyExtractionTypesThrowsGeminaException(): void
    {
        $client = $this->client($this->mockDocuments());

        $this->expectException(GeminaException::class);
        $client->processDocument($this->tempInvoicePath(), []);
    }

    public function testMissingFileThrowsGeminaException(): void
    {
        $client = $this->client($this->mockDocuments());

        $this->expectException(GeminaException::class);
        $this->expectExceptionMessage('File not found');
        $client->processDocument('/no/such/file.png', ['invoice_headers']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function mockDocuments(): DocumentApi&MockObject
    {
        return $this->createMock(DocumentApi::class);
    }

    /**
     * @param list<Response> $submitResponses queued HTTP responses for the
     *        hand-rolled multipart submit (file sources only)
     */
    private function client(
        DocumentApi $documents,
        float $randomValue = 0.5,
        array $submitResponses = [],
    ): GeminaClient {
        $this->sleeps = [];
        $this->httpHistory = [];

        $handlerStack = HandlerStack::create(new MockHandler($submitResponses));
        $handlerStack->push(Middleware::history($this->httpHistory));

        return new GeminaClient('test-api-key', 'https://api.example.invalid', [
            'httpClient' => new Client(['handler' => $handlerStack]),
            'apis' => ['documents' => $documents],
            'sleeper' => function (float $seconds): void {
                $this->sleeps[] = $seconds;
            },
            'random' => static fn (): float => $randomValue,
        ]);
    }

    private function processingResult(string $status, ?string $correlationId = self::CORRELATION_ID): DocumentProcessingResultOutDTO
    {
        $data = ['status' => $status];
        if ($correlationId !== null) {
            $data['meta'] = new DocumentProcessingMetaOutDTO(['correlation_id' => $correlationId]);
        }

        return new DocumentProcessingResultOutDTO($data);
    }

    private function resultJson(string $status, ?string $correlationId = self::CORRELATION_ID): string
    {
        return json_encode([
            'status' => $status,
            'meta' => ['correlationId' => $correlationId],
        ], JSON_THROW_ON_ERROR);
    }

    private function urlSource(): UrlSource
    {
        return new UrlSource('https://example.com/invoice.pdf');
    }

    private function tempInvoicePath(): string
    {
        if ($this->tempFile === '') {
            $this->tempFile = sys_get_temp_dir() . '/gemina-sdk-test-' . bin2hex(random_bytes(6)) . '.png';
            file_put_contents($this->tempFile, 'fake-image-bytes');
        }

        return $this->tempFile;
    }
}
