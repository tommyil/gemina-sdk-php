<?php

declare(strict_types=1);

namespace Gemina\Sdk;

use Gemina\Sdk\Api\BillingApi;
use Gemina\Sdk\Api\ChatApi;
use Gemina\Sdk\Api\DocumentApi;
use Gemina\Sdk\Api\FilesApi;
use Gemina\Sdk\Api\FileTagApi;
use Gemina\Sdk\Api\RetrievalApi;
use Gemina\Sdk\Api\SessionsApi;
use Gemina\Sdk\Api\SubscriptionsApi;
use Gemina\Sdk\Api\TemplatesApi;
use Gemina\Sdk\Model\DocumentProcessingResultOutDTO;
use Gemina\Sdk\Model\ResponseStatus;
use Gemina\Sdk\Model\WebDocumentUploadInDTO;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Utils;

/**
 * Hand-written facade over the generated Gemina API client.
 *
 * - Authenticates with your API key (sent as the X-API-Key header).
 * - Exposes every generated API group via lazy accessors (documents(),
 *   retrieval(), chat(), ...) — the full generated surface, zero wrapping.
 * - Provides processDocument(): submit via the async endpoints, poll with
 *   exponential backoff until terminal, return the typed result.
 */
class GeminaClient
{
    public const DEFAULT_BASE_URL = 'https://api.gemina.co';

    private const NON_TERMINAL_STATUSES = [
        ResponseStatus::PENDING,
        ResponseStatus::IN_PROCESS,
    ];

    private const DEFAULT_TIMEOUT_SECONDS = 300.0;
    private const DEFAULT_INITIAL_INTERVAL_SECONDS = 2.0;
    private const DEFAULT_MAX_INTERVAL_SECONDS = 15.0;
    private const BACKOFF_MULTIPLIER = 1.5;
    private const MAX_CONSECUTIVE_POLL_FAILURES = 3;

    private Configuration $configuration;

    private ClientInterface $httpClient;

    /** @var array<string, object> Generated Api instances keyed by accessor name. */
    private array $apis = [];

    /** @var callable(float): void Sleeps for the given number of seconds. */
    private $sleeper;

    /** @var callable(): float Returns a float in [0, 1). */
    private $random;

    /**
     * @param string $apiKey  API key from https://console.gemina.co (sent as X-API-Key).
     * @param string $baseUrl API base URL (override for staging / self-hosted).
     * @param array{
     *     httpClient?: ClientInterface,
     *     sleeper?: callable(float): void,
     *     random?: callable(): float,
     *     apis?: array<string, object>,
     * } $options Advanced/testing hooks: a custom Guzzle client, an injectable
     *            sleep function and RNG for the polling loop, and pre-built
     *            generated Api instances keyed by accessor name
     *            ('documents', 'retrieval', ...).
     */
    public function __construct(string $apiKey, string $baseUrl = self::DEFAULT_BASE_URL, array $options = [])
    {
        $this->configuration = new Configuration();
        if ($apiKey !== '') {
            // The generated client looks the API key up by header name.
            $this->configuration->setApiKey('X-API-Key', $apiKey);
        }
        $this->configuration->setHost(rtrim($baseUrl, '/'));
        $this->configuration->setUserAgent('gemina-sdk-php/' . SdkVersion::VERSION);

        $this->httpClient = $options['httpClient'] ?? new Client();

        $this->sleeper = $options['sleeper'] ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
        $this->random = $options['random'] ?? static function (): float {
            return mt_rand() / (mt_getrandmax() + 1);
        };

        foreach ($options['apis'] ?? [] as $name => $api) {
            $this->apis[$name] = $api;
        }
    }

    /**
     * Build a client authenticated with a short-lived session token
     * (Authorization: Bearer) instead of an API key. Session tokens are
     * minted server-side via POST /v1/sessions/token.
     */
    public static function withSessionToken(string $token, string $baseUrl = self::DEFAULT_BASE_URL, array $options = []): self
    {
        $client = new self('', $baseUrl, $options);
        $client->configuration->setAccessToken($token);

        return $client;
    }

    public function getConfiguration(): Configuration
    {
        return $this->configuration;
    }

    // ------------------------------------------------------------------
    // Generated API group accessors (lazily constructed, shared transport)
    // ------------------------------------------------------------------

    public function documents(): DocumentApi
    {
        return $this->apis['documents'] ??= new DocumentApi($this->httpClient, $this->configuration);
    }

    public function retrieval(): RetrievalApi
    {
        return $this->apis['retrieval'] ??= new RetrievalApi($this->httpClient, $this->configuration);
    }

    public function chat(): ChatApi
    {
        return $this->apis['chat'] ??= new ChatApi($this->httpClient, $this->configuration);
    }

    public function templates(): TemplatesApi
    {
        return $this->apis['templates'] ??= new TemplatesApi($this->httpClient, $this->configuration);
    }

    public function files(): FilesApi
    {
        return $this->apis['files'] ??= new FilesApi($this->httpClient, $this->configuration);
    }

    public function fileTag(): FileTagApi
    {
        return $this->apis['fileTag'] ??= new FileTagApi($this->httpClient, $this->configuration);
    }

    public function sessions(): SessionsApi
    {
        return $this->apis['sessions'] ??= new SessionsApi($this->httpClient, $this->configuration);
    }

    public function subscriptions(): SubscriptionsApi
    {
        return $this->apis['subscriptions'] ??= new SubscriptionsApi($this->httpClient, $this->configuration);
    }

    public function billing(): BillingApi
    {
        return $this->apis['billing'] ??= new BillingApi($this->httpClient, $this->configuration);
    }

    // ------------------------------------------------------------------
    // processDocument — the headline one-call flow
    // ------------------------------------------------------------------

    /**
     * Submit a document via the async endpoints, poll until terminal, and
     * return the typed result. Blocks the calling thread while polling.
     *
     * Terminal semantics:
     * - "success" | "partial" | "empty": the result is returned (check
     *   getStatus(); partial/empty still carry usable data/meta).
     * - "failed": throws GeminaProcessingException carrying the full result.
     * - Deadline exceeded: throws GeminaTimeoutException carrying the
     *   correlation ID and the last seen result.
     *
     * @param string|\SplFileObject|UrlSource $source File path, open file, or
     *        a UrlSource pointing at a downloadable document.
     * @param string[] $extractionTypes Non-empty list of extraction types
     *        (values of \Gemina\Sdk\Model\ExtractionTypeModel, e.g.
     *        'invoice_headers').
     * @param array{
     *     externalId?: string,
     *     templateId?: string,
     *     modelType?: string,
     *     thinking?: bool,
     *     evaluation?: bool,
     *     correction?: bool,
     *     includeCoordinates?: bool,
     *     endUserId?: string,
     *     timeoutSeconds?: float,
     *     initialIntervalSeconds?: float,
     *     maxIntervalSeconds?: float,
     *     sleeper?: callable(float): void,
     *     random?: callable(): float,
     * } $options Endpoint form fields plus polling knobs. externalId defaults
     *            to a generated unique ID.
     *
     * @throws GeminaException           Malformed server response / bad input.
     * @throws GeminaProcessingException Terminal "failed" status (including
     *                                   failed results the API reports as HTTP 500).
     * @throws GeminaTimeoutException    Polling deadline exceeded.
     * @throws ApiException              Transport/HTTP errors pass through unwrapped.
     *                                   Submit errors are never retried; transient
     *                                   poll errors are retried on the backoff
     *                                   schedule and rethrown unchanged after 3
     *                                   consecutive failures.
     */
    public function processDocument(
        string|\SplFileObject|UrlSource $source,
        array $extractionTypes,
        array $options = [],
    ): DocumentProcessingResultOutDTO {
        if ($extractionTypes === []) {
            throw new GeminaException('extractionTypes must be a non-empty list.');
        }

        $timeoutSeconds = (float) ($options['timeoutSeconds'] ?? self::DEFAULT_TIMEOUT_SECONDS);
        $initialIntervalSeconds = (float) ($options['initialIntervalSeconds'] ?? self::DEFAULT_INITIAL_INTERVAL_SECONDS);
        $maxIntervalSeconds = (float) ($options['maxIntervalSeconds'] ?? self::DEFAULT_MAX_INTERVAL_SECONDS);
        $sleeper = $options['sleeper'] ?? $this->sleeper;
        $random = $options['random'] ?? $this->random;

        try {
            $result = $this->submit($source, $extractionTypes, $options);
        } catch (ApiException $e) {
            throw $this->failedResultException($e) ?? $e;
        }

        if ($this->isTerminal($result)) {
            return $this->finalize($result);
        }

        $meta = $result->getMeta();
        $correlationId = $meta !== null ? $meta->getCorrelationId() : null;
        if ($correlationId === null || $correlationId === '') {
            throw new GeminaException(
                'Server returned a non-terminal response without meta.correlationId.',
            );
        }

        $nominalInterval = $initialIntervalSeconds;
        $elapsedSeconds = 0.0;
        $lastResult = $result;
        $consecutivePollFailures = 0;

        while (true) {
            if ($elapsedSeconds >= $timeoutSeconds) {
                throw new GeminaTimeoutException($correlationId, $lastResult);
            }

            $jitter = 0.8 + 0.4 * (float) $random();
            $waitSeconds = min($nominalInterval, $maxIntervalSeconds) * $jitter;
            $sleeper($waitSeconds);
            $elapsedSeconds += $waitSeconds;
            $nominalInterval *= self::BACKOFF_MULTIPLIER;

            try {
                $lastResult = $this->expectResult(
                    $this->documents()->getDocumentProcessingResultByCorrelationId($correlationId),
                );
                $consecutivePollFailures = 0;
            } catch (ApiException $e) {
                $failed = $this->failedResultException($e);
                if ($failed !== null) {
                    throw $failed;
                }

                // Transient poll failure (connection error / 5xx with a
                // non-result body). The document is already submitted, so a
                // load-balancer blip must not orphan it: keep polling on the
                // same backoff schedule and overall deadline, but give up
                // after MAX_CONSECUTIVE_POLL_FAILURES in a row and rethrow
                // the last error unchanged.
                if (++$consecutivePollFailures >= self::MAX_CONSECUTIVE_POLL_FAILURES) {
                    throw $e;
                }

                continue;
            }

            if ($this->isTerminal($lastResult)) {
                return $this->finalize($lastResult);
            }
        }
    }

    /**
     * @param string[] $extractionTypes
     * @param array<string, mixed> $options
     */
    private function submit(
        string|\SplFileObject|UrlSource $source,
        array $extractionTypes,
        array $options,
    ): DocumentProcessingResultOutDTO {
        $externalId = (string) ($options['externalId'] ?? ('php-sdk-' . bin2hex(random_bytes(8))));

        if ($source instanceof UrlSource) {
            $dto = new WebDocumentUploadInDTO([
                'url' => $source->url,
                'external_id' => $externalId,
                'extraction_types' => $extractionTypes,
                'correction' => $options['correction'] ?? null,
                'end_user_id' => $options['endUserId'] ?? null,
                'evaluation' => $options['evaluation'] ?? null,
                'include_coordinates' => $options['includeCoordinates'] ?? null,
                'model_type' => $options['modelType'] ?? null,
                'template_id' => $options['templateId'] ?? null,
                'thinking' => $options['thinking'] ?? null,
            ]);

            return $this->expectResult(
                $this->documents()->createWebDocumentProcessingRequest($dto),
            );
        }

        return $this->submitFileMultipart($this->toFileObject($source), $extractionTypes, $externalId, $options);
    }

    /**
     * POST /api/v1/documents/requests (multipart).
     *
     * Hand-built instead of the generated
     * DocumentApi::createDocumentProcessingRequest(): the generated
     * FormDataProcessor flattens list form fields into bracket notation
     * ("extraction_types[0]"), which the API rejects — it expects repeated
     * "extraction_types" parts. Auth, host and user-agent still come from the
     * shared generated Configuration, and errors are raised as the generated
     * ApiException for consistency.
     *
     * @param string[] $extractionTypes
     * @param array<string, mixed> $options
     */
    private function submitFileMultipart(
        \SplFileObject $file,
        array $extractionTypes,
        string $externalId,
        array $options,
    ): DocumentProcessingResultOutDTO {
        $parts = [
            ['name' => 'external_id', 'contents' => $externalId],
        ];
        foreach ($extractionTypes as $extractionType) {
            $parts[] = ['name' => 'extraction_types', 'contents' => (string) $extractionType];
        }

        foreach ([
            'correction' => 'correction',
            'endUserId' => 'end_user_id',
            'evaluation' => 'evaluation',
            'includeCoordinates' => 'include_coordinates',
            'modelType' => 'model_type',
            'templateId' => 'template_id',
            'thinking' => 'thinking',
        ] as $optionKey => $fieldName) {
            if (isset($options[$optionKey])) {
                $parts[] = ['name' => $fieldName, 'contents' => ObjectSerializer::toString($options[$optionKey])];
            }
        }

        $path = $file->getRealPath() !== false ? $file->getRealPath() : $file->getPathname();
        $parts[] = [
            'name' => 'file',
            'contents' => Utils::tryFopen($path, 'rb'),
            'filename' => basename($file->getPathname()),
        ];

        $body = new MultipartStream($parts);
        $headers = [
            'Content-Type' => 'multipart/form-data; boundary=' . $body->getBoundary(),
            'Accept' => 'application/json',
        ];
        if ($this->configuration->getUserAgent()) {
            $headers['User-Agent'] = $this->configuration->getUserAgent();
        }
        $apiKey = $this->configuration->getApiKeyWithPrefix('X-API-Key');
        if ($apiKey !== null) {
            $headers['X-API-Key'] = $apiKey;
        }
        if (!empty($this->configuration->getAccessToken())) {
            $headers['Authorization'] = 'Bearer ' . $this->configuration->getAccessToken();
        }

        $request = new Request(
            'POST',
            $this->configuration->getHost() . '/api/v1/documents/requests',
            $headers,
            $body,
        );

        try {
            $response = $this->httpClient->send($request);
        } catch (RequestException $e) {
            throw new ApiException(
                sprintf('[%d] %s', (int) $e->getCode(), $e->getMessage()),
                (int) $e->getCode(),
                $e->getResponse() !== null ? $e->getResponse()->getHeaders() : null,
                $e->getResponse() !== null ? (string) $e->getResponse()->getBody() : null,
            );
        } catch (ConnectException $e) {
            throw new ApiException(
                sprintf('[%d] %s', (int) $e->getCode(), $e->getMessage()),
                (int) $e->getCode(),
                null,
                null,
            );
        }

        $statusCode = $response->getStatusCode();
        $content = (string) $response->getBody();

        if ($statusCode < 200 || $statusCode > 299) {
            throw new ApiException(
                sprintf(
                    '[%d] Error connecting to the API (%s)',
                    $statusCode,
                    (string) $request->getUri(),
                ),
                $statusCode,
                $response->getHeaders(),
                $content,
            );
        }

        try {
            $decoded = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new GeminaException(
                'Server returned a non-JSON response from the document submit endpoint.',
                0,
                $e,
            );
        }

        return $this->expectResult(
            ObjectSerializer::deserialize($decoded, DocumentProcessingResultOutDTO::class),
        );
    }

    private function toFileObject(string|\SplFileObject $source): \SplFileObject
    {
        if ($source instanceof \SplFileObject) {
            return $source;
        }

        if (!is_file($source)) {
            throw new GeminaException(sprintf('File not found: %s', $source));
        }

        return new \SplFileObject($source, 'rb');
    }

    private function expectResult(mixed $response): DocumentProcessingResultOutDTO
    {
        if (!$response instanceof DocumentProcessingResultOutDTO) {
            throw new GeminaException(sprintf(
                'Unexpected response type from the document endpoint: %s',
                get_debug_type($response),
            ));
        }

        return $response;
    }

    /**
     * The API reports a terminally failed document as an HTTP 500 whose body
     * IS a DocumentProcessingResultOutDTO with status=failed, which the
     * generated client (and the hand-built submit) surface as ApiException.
     * If the exception's response body parses to such a failed result, return
     * the GeminaProcessingException to throw instead; otherwise return null —
     * the caller then treats the original ApiException as transport-level
     * (submit: rethrown unchanged; poll: retried as a transient failure).
     */
    private function failedResultException(ApiException $e): ?GeminaProcessingException
    {
        $body = $e->getResponseBody();
        if (!is_string($body) || $body === '') {
            return null;
        }

        try {
            $decoded = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_object($decoded)) {
            return null;
        }

        try {
            $result = ObjectSerializer::deserialize($decoded, DocumentProcessingResultOutDTO::class);
        } catch (\Throwable) {
            return null;
        }

        if ($result instanceof DocumentProcessingResultOutDTO
            && (string) $result->getStatus() === ResponseStatus::FAILED
        ) {
            return new GeminaProcessingException($result);
        }

        return null;
    }

    private function isTerminal(DocumentProcessingResultOutDTO $result): bool
    {
        return !in_array((string) $result->getStatus(), self::NON_TERMINAL_STATUSES, true);
    }

    private function finalize(DocumentProcessingResultOutDTO $result): DocumentProcessingResultOutDTO
    {
        if ((string) $result->getStatus() === ResponseStatus::FAILED) {
            throw new GeminaProcessingException($result);
        }

        return $result;
    }
}
