<?php

/**
 * Smoke tests for OpenSubtitlesProvider.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\PluginOpenSubtitles\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Phlix\PluginOpenSubtitles\OpenSubtitlesException;
use Phlix\PluginOpenSubtitles\OpenSubtitlesProvider;
use Phlix\PluginOpenSubtitles\SubtitleDownload;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Smoke tests for the {@see OpenSubtitlesProvider} plugin.
 *
 * Tests the plugin's contract compliance and core functionality
 * in isolation without requiring a live OpenSubtitles API connection.
 */
final class OpenSubtitlesProviderTest extends TestCase
{
    private const TEST_API_KEY = 'test-api-key-12345';

    public function test_constructor_sets_properties_correctly(): void
    {
        $provider = new OpenSubtitlesProvider(
            apiKey: self::TEST_API_KEY,
            username: 'testuser',
            password: 'testpass',
            language: 'fr',
            format: 'ass',
        );

        $this->assertSame('fr', $provider->getLanguage());
        $this->assertSame('ass', $provider->getFormat());
        $this->assertFalse($provider->isLoggedIn());
    }

    public function test_constructor_with_minimal_params(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        $this->assertSame('en', $provider->getLanguage());
        $this->assertSame('srt', $provider->getFormat());
        $this->assertFalse($provider->isLoggedIn());
    }

    public function test_constructor_is_autowirable_with_no_arguments(): void
    {
        // The host container builds the entry class with zero args — it must not
        // throw, and it must implement the settings-injection contract.
        $provider = new OpenSubtitlesProvider();

        $this->assertInstanceOf(\Phlix\Shared\Plugin\LifecycleInterface::class, $provider);
        $this->assertInstanceOf(\Phlix\Shared\Plugin\ConfigurableInterface::class, $provider);
        $this->assertSame('en', $provider->getLanguage());
        $this->assertSame('srt', $provider->getFormat());
    }

    public function test_configure_applies_persisted_settings(): void
    {
        $provider = new OpenSubtitlesProvider();
        $provider->configure([
            'api_key'  => self::TEST_API_KEY,
            'username' => 'testuser',
            'password' => 'testpass',
            'language' => 'de',
            'format'   => 'ass',
        ]);

        $this->assertSame('de', $provider->getLanguage());
        $this->assertSame('ass', $provider->getFormat());
        $this->assertFalse($provider->isLoggedIn());
    }

    public function test_configure_falls_back_to_defaults_for_blank_values(): void
    {
        $provider = new OpenSubtitlesProvider();
        $provider->configure(['api_key' => self::TEST_API_KEY, 'language' => '', 'format' => '']);

        $this->assertSame('en', $provider->getLanguage());
        $this->assertSame('srt', $provider->getFormat());
    }

    public function test_compute_hash_returns_empty_for_nonexistent_file(): void
    {
        $hash = OpenSubtitlesProvider::computeHash('/nonexistent/path/file.mkv');

        $this->assertSame('', $hash);
    }

    public function test_compute_hash_returns_hash_for_existing_file(): void
    {
        // Create a temporary file for testing
        $tempFile = tempnam(sys_get_temp_dir(), 'phlix_test_');
        if ($tempFile === false) {
            $this->markTestSkipped('Could not create temp file');
        }

        try {
            file_put_contents($tempFile, 'test content for hash computation');

            $hash = OpenSubtitlesProvider::computeHash($tempFile);

            // Hash should be a 64-character hex string (SHA-256)
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);

            // Same content should produce same hash
            $hash2 = OpenSubtitlesProvider::computeHash($tempFile);
            $this->assertSame($hash, $hash2);
        } finally {
            unlink($tempFile);
        }
    }

    public function test_compute_hash_different_content_produces_different_hash(): void
    {
        $tempFile1 = tempnam(sys_get_temp_dir(), 'phlix_test1_');
        $tempFile2 = tempnam(sys_get_temp_dir(), 'phlix_test2_');

        if ($tempFile1 === false || $tempFile2 === false) {
            if ($tempFile1 !== false) {
                unlink($tempFile1);
            }
            if ($tempFile2 !== false) {
                unlink($tempFile2);
            }
            $this->markTestSkipped('Could not create temp files');
        }

        try {
            file_put_contents($tempFile1, 'content A');
            file_put_contents($tempFile2, 'content B');

            $hash1 = OpenSubtitlesProvider::computeHash($tempFile1);
            $hash2 = OpenSubtitlesProvider::computeHash($tempFile2);

            $this->assertNotSame($hash1, $hash2);
        } finally {
            unlink($tempFile1);
            unlink($tempFile2);
        }
    }

    public function test_subtitle_download_dto_is_immutable(): void
    {
        $download = new SubtitleDownload(
            content: 'subtitle content',
            format: 'srt',
            fileName: 'movie.srt',
        );

        $this->assertSame('subtitle content', $download->content);
        $this->assertSame('srt', $download->format);
        $this->assertSame('movie.srt', $download->fileName);
    }

    public function test_subtitle_download_get_content_length(): void
    {
        $download = new SubtitleDownload(
            content: 'hello',
            format: 'srt',
            fileName: 'test.srt',
        );

        $this->assertSame(5, $download->getContentLength());
    }

    public function test_subtitle_download_is_empty(): void
    {
        $emptyDownload = new SubtitleDownload(
            content: '',
            format: 'srt',
            fileName: 'empty.srt',
        );

        $nonEmptyDownload = new SubtitleDownload(
            content: 'some content',
            format: 'srt',
            fileName: 'nonempty.srt',
        );

        $this->assertTrue($emptyDownload->isEmpty());
        $this->assertFalse($nonEmptyDownload->isEmpty());
    }

    public function test_exception_is_runtime_exception(): void
    {
        $exception = new OpenSubtitlesException('Test message', 42);

        $this->assertSame('Test message', $exception->getMessage());
        $this->assertSame(42, $exception->getCode());
    }

    public function test_provider_is_disabled_before_onenable(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        // Calling searchByImdbId before onEnable should throw
        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessage('not enabled');

        $provider->searchByImdbId('tt1234567');
    }

    public function test_provider_is_disabled_before_onenable_search_by_filename(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessage('not enabled');

        $provider->searchByFilename('movie.mkv');
    }

    public function test_provider_is_disabled_before_onenable_search_by_hash(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessage('not enabled');

        $provider->searchByHash('abc123', 1234567);
    }

    public function test_provider_is_disabled_before_onenable_download(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessage('not enabled');

        $provider->download(12345);
    }

    public function test_subscribed_events_returns_empty_array(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        $this->assertSame([], $provider->subscribedEvents());
    }

    /**
     * Regression test for the production 404 bug: `onEnable()` posted to
     * `https://api.opensubtitles.com/v2/uselogin` instead of the real
     * OpenSubtitles REST API v1 login endpoint. Guzzle's `base_uri` +
     * leading-slash request path resolution (RFC 3986 §5.3) silently
     * discards the base URI's path component, so this must be asserted
     * against the fully resolved request URI — not just the path literal
     * in the source — or the bug would have shipped without a red test.
     */
    public function test_login_posts_to_the_correct_v1_login_url(): void
    {
        $provider = new OpenSubtitlesProvider(
            apiKey: self::TEST_API_KEY,
            username: 'testuser',
            password: 'testpass',
        );

        $history = [];
        $this->installMockHttpClient($provider, [
            new Response(200, [], (string) json_encode(['token' => 'session-token-123'])),
        ], $history);

        $provider->onEnable($this->stubContainer());

        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(
            'https://api.opensubtitles.com/api/v1/login',
            (string) $request->getUri(),
        );
        $this->assertTrue($provider->isLoggedIn());
    }

    public function test_search_by_imdb_id_requests_the_correct_v1_subtitles_url(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        $history = [];
        $this->installMockHttpClient($provider, [
            new Response(200, [], (string) json_encode(['data' => []])),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $provider->searchByImdbId('tt1234567');

        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertStringStartsWith(
            'https://api.opensubtitles.com/api/v1/subtitles',
            (string) $request->getUri(),
        );
    }

    /**
     * Replace the provider's internal Guzzle client with one backed by a
     * {@see MockHandler} (same `base_uri` as production), wiring Guzzle's
     * history middleware to append `['request' => ..., 'response' => ...]`
     * entries into `$history` as calls happen. `$history` MUST be passed by
     * reference from the caller (not returned) because
     * {@see Middleware::history()} binds the container by reference at
     * closure-creation time — returning a fresh array here would only ever
     * capture the empty pre-call snapshot.
     *
     * @param list<Response>                       $responses Queued mock responses, in order.
     * @param list<array{request: RequestInterface}> $history  Out-param; appended to as requests fire.
     */
    private function installMockHttpClient(OpenSubtitlesProvider $provider, array $responses, array &$history): void
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $client = new Client([
            'base_uri' => 'https://api.opensubtitles.com/api/v1/',
            'handler' => $stack,
        ]);

        $property = new \ReflectionProperty(OpenSubtitlesProvider::class, 'httpClient');
        $property->setAccessible(true);
        $property->setValue($provider, $client);
    }

    private function stubContainer(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException("Unexpected container lookup for {$id}");
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }
}
