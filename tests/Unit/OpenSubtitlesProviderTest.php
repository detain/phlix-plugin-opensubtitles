<?php

/**
 * Tests for OpenSubtitlesProvider.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\PluginOpenSubtitles\Tests;

use Phlix\PluginOpenSubtitles\OpenSubtitlesException;
use Phlix\PluginOpenSubtitles\OpenSubtitlesProvider;
use Phlix\PluginOpenSubtitles\OpenSubtitlesQuotaExceededException;
use Phlix\PluginOpenSubtitles\SubtitleDownload;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests the plugin's contract compliance and core functionality in isolation.
 *
 * The provider's network layer is exercised through its `transport` closure
 * seam — a fake that records outgoing requests and returns queued
 * `{status, body}` responses — so no Workerman event loop or live API is
 * needed. This asserts real consequences: what URLs/bodies/headers are sent,
 * that onEnable does zero HTTP, and that the moviehash math is correct.
 */
final class OpenSubtitlesProviderTest extends TestCase
{
    private const TEST_API_KEY = 'test-api-key-12345';

    // ---------------------------------------------------------------------
    // Construction / configuration
    // ---------------------------------------------------------------------

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

    // ---------------------------------------------------------------------
    // Boot safety: onEnable must not do HTTP / must not log in
    // ---------------------------------------------------------------------

    /**
     * The keystone boot-safety assertion. onEnable runs across ~14 Workerman
     * workers at boot; a blocking login there is the item-5c3 landmine that was
     * reverted in production. Even with credentials configured, onEnable must
     * make ZERO HTTP requests and must NOT be logged in — authentication is
     * deferred to the first actual API call.
     */
    public function test_on_enable_does_no_http_and_no_login_even_with_credentials(): void
    {
        $provider = new OpenSubtitlesProvider(
            apiKey: self::TEST_API_KEY,
            username: 'testuser',
            password: 'testpass',
        );

        $history = [];
        $this->installFakeTransport($provider, [], $history);

        $provider->onEnable($this->stubContainer());

        $this->assertSame([], $history, 'onEnable must not perform any HTTP request');
        $this->assertFalse($provider->isLoggedIn(), 'onEnable must not log in');
    }

    public function test_subscribed_events_returns_empty_array(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        $this->assertSame([], $provider->subscribedEvents());
    }

    // ---------------------------------------------------------------------
    // Deferred login (happens lazily on first authenticated use)
    // ---------------------------------------------------------------------

    /**
     * Login is deferred out of onEnable to the first authenticated call. This
     * asserts (a) it fires on the first search, not at enable time, (b) it
     * POSTs to the correct fully-resolved v1 login URL, and (c) the session
     * token is captured. The login request must precede the search request.
     */
    public function test_login_is_deferred_to_first_use_and_posts_to_correct_v1_url(): void
    {
        $provider = new OpenSubtitlesProvider(
            apiKey: self::TEST_API_KEY,
            username: 'testuser',
            password: 'testpass',
        );

        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode(['token' => 'session-token-123'])),
            self::response(200, (string) json_encode(['data' => []])),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $this->assertCount(0, $history, 'no request until first use');

        $provider->searchByImdbId('tt1234567');

        $this->assertCount(2, $history);
        $this->assertSame('POST', $history[0]['method']);
        $this->assertSame('https://api.opensubtitles.com/api/v1/login', $history[0]['url']);
        $this->assertTrue($provider->isLoggedIn());
        $this->assertStringStartsWith(
            'https://api.opensubtitles.com/api/v1/subtitles',
            $history[1]['url'],
        );
    }

    /**
     * Login runs at most once per enable cycle: a second search must not re-POST
     * to /login (that would burn requests and defeat the token).
     */
    public function test_login_runs_only_once_per_enable_cycle(): void
    {
        $provider = new OpenSubtitlesProvider(
            apiKey: self::TEST_API_KEY,
            username: 'u',
            password: 'p',
        );

        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode(['token' => 'tok'])),
            self::response(200, (string) json_encode(['data' => []])),
            self::response(200, (string) json_encode(['data' => []])),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $provider->searchByImdbId('tt1');
        $provider->searchByImdbId('tt2');

        $loginCalls = array_filter($history, static fn (array $h): bool => str_ends_with($h['url'], '/login'));
        $this->assertCount(1, $loginCalls);
    }

    /**
     * Login is optional (it only raises quota limits). A failed login must NOT
     * throw or abort the search — the provider proceeds anonymously.
     */
    public function test_failed_login_does_not_break_search(): void
    {
        $provider = new OpenSubtitlesProvider(
            apiKey: self::TEST_API_KEY,
            username: 'u',
            password: 'p',
        );

        $history = [];
        $this->installFakeTransport($provider, [
            self::response(401, (string) json_encode(['message' => 'bad credentials'])),
            self::response(200, (string) json_encode(['data' => []])),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $results = $provider->searchByImdbId('tt1234567');

        $this->assertSame([], $results);
        $this->assertFalse($provider->isLoggedIn());
    }

    // ---------------------------------------------------------------------
    // Default headers (User-Agent derived from plugin.json)
    // ---------------------------------------------------------------------

    /**
     * Regression test for a stale hardcoded User-Agent. The header must be
     * derived from plugin.json's version at runtime. Asserted against the actual
     * outgoing request headers (via the transport seam), not a source literal.
     */
    public function test_request_user_agent_derives_from_plugin_json_version(): void
    {
        $manifestPath = dirname(__DIR__, 2) . '/plugin.json';
        /** @var array<string, mixed> $manifest */
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $expectedVersion = $manifest['version'];
        $this->assertIsString($expectedVersion);
        $this->assertNotSame('', $expectedVersion);

        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [self::response(200, '{"data":[]}')], $history);

        $provider->onEnable($this->stubContainer());
        $provider->searchByImdbId('tt1234567');

        $this->assertSame(
            'Phlix-Plugin-OpenSubtitles/' . $expectedVersion,
            $history[0]['headers']['User-Agent'],
        );
        $this->assertNotSame('Phlix-Plugin-OpenSubtitles/0.1.0', $history[0]['headers']['User-Agent']);
        $this->assertSame(self::TEST_API_KEY, $history[0]['headers']['Api-Key']);
    }

    // ---------------------------------------------------------------------
    // moviehash — the OpenSubtitles OSDb hash algorithm
    // ---------------------------------------------------------------------

    public function test_compute_hash_returns_empty_for_nonexistent_file(): void
    {
        $this->assertSame('', OpenSubtitlesProvider::computeMovieHash('/nonexistent/path/file.mkv'));
    }

    /**
     * Files smaller than one 64 KiB chunk cannot produce a valid moviehash.
     */
    public function test_compute_hash_returns_empty_for_file_smaller_than_a_chunk(): void
    {
        $file = $this->makeFixture(str_repeat("\0", 1024));

        try {
            $this->assertSame('', OpenSubtitlesProvider::computeMovieHash($file));
        } finally {
            unlink($file);
        }
    }

    /**
     * Documented-value vector: a 131072-byte all-zero file. Every 8-byte word of
     * both chunks sums to zero, so the hash reduces to the file size itself
     * (131072 = 0x20000), rendered as 16 zero-padded lowercase hex chars. This
     * value is derived independently of the implementation and pins the exact
     * algorithm (size-seed + little-endian word sum + %016x formatting).
     */
    public function test_compute_hash_matches_documented_value_for_all_zero_file(): void
    {
        $file = $this->makeFixture(str_repeat("\0", 131072));

        try {
            $this->assertSame('0000000000020000', OpenSubtitlesProvider::computeMovieHash($file));
        } finally {
            unlink($file);
        }
    }

    /**
     * The summation must actually contribute: changing a byte inside the first
     * 64 KiB must change the hash (discriminates a broken/no-op accumulator).
     */
    public function test_compute_hash_changes_when_a_head_byte_changes(): void
    {
        $baseline = str_repeat("\0", 131072);
        $mutated = $baseline;
        $mutated[10] = "\xAB"; // within the first 64 KiB chunk

        $fileA = $this->makeFixture($baseline);
        $fileB = $this->makeFixture($mutated);

        try {
            $hashA = OpenSubtitlesProvider::computeMovieHash($fileA);
            $hashB = OpenSubtitlesProvider::computeMovieHash($fileB);
            $this->assertSame('0000000000020000', $hashA);
            $this->assertNotSame($hashA, $hashB);
        } finally {
            unlink($fileA);
            unlink($fileB);
        }
    }

    /**
     * Proves the hash reads ONLY the head and tail: mutating a byte in the
     * middle of a file large enough to have a gap between the two 64 KiB windows
     * must NOT change the hash.
     */
    public function test_compute_hash_ignores_the_middle_of_the_file(): void
    {
        $size = 200000; // head [0,65536), tail [134464,200000) — gap in between
        $baseline = str_repeat("\0", $size);
        $mutated = $baseline;
        $mutated[100000] = "\xFF"; // squarely in the untouched middle

        $fileA = $this->makeFixture($baseline);
        $fileB = $this->makeFixture($mutated);

        try {
            $this->assertSame(
                OpenSubtitlesProvider::computeMovieHash($fileA),
                OpenSubtitlesProvider::computeMovieHash($fileB),
            );
        } finally {
            unlink($fileA);
            unlink($fileB);
        }
    }

    public function test_compute_hash_is_16_char_lowercase_hex_and_deterministic(): void
    {
        $file = $this->makeFixture(str_repeat("phlix-", 40000)); // > 128 KiB

        try {
            $hash = OpenSubtitlesProvider::computeMovieHash($file);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $hash);
            $this->assertSame($hash, OpenSubtitlesProvider::computeMovieHash($file));
        } finally {
            unlink($file);
        }
    }

    // ---------------------------------------------------------------------
    // SubtitleDownload DTO / exception
    // ---------------------------------------------------------------------

    public function test_subtitle_download_dto_is_immutable(): void
    {
        $download = new SubtitleDownload(content: 'subtitle content', format: 'srt', fileName: 'movie.srt');

        $this->assertSame('subtitle content', $download->content);
        $this->assertSame('srt', $download->format);
        $this->assertSame('movie.srt', $download->fileName);
    }

    public function test_subtitle_download_get_content_length(): void
    {
        $download = new SubtitleDownload(content: 'hello', format: 'srt', fileName: 'test.srt');

        $this->assertSame(5, $download->getContentLength());
    }

    public function test_subtitle_download_is_empty(): void
    {
        $this->assertTrue((new SubtitleDownload(content: '', format: 'srt', fileName: 'e.srt'))->isEmpty());
        $this->assertFalse((new SubtitleDownload(content: 'x', format: 'srt', fileName: 'n.srt'))->isEmpty());
    }

    public function test_exception_is_runtime_exception(): void
    {
        $exception = new OpenSubtitlesException('Test message', 42);

        $this->assertSame('Test message', $exception->getMessage());
        $this->assertSame(42, $exception->getCode());
    }

    // ---------------------------------------------------------------------
    // Not-enabled guards
    // ---------------------------------------------------------------------

    public function test_search_by_imdb_id_throws_before_onenable(): void
    {
        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessage('not enabled');
        (new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY))->searchByImdbId('tt1234567');
    }

    public function test_search_by_filename_throws_before_onenable(): void
    {
        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessage('not enabled');
        (new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY))->searchByFilename('movie.mkv');
    }

    public function test_search_by_hash_throws_before_onenable(): void
    {
        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessage('not enabled');
        (new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY))->searchByHash('abc123', 1234567);
    }

    public function test_search_by_path_throws_before_onenable(): void
    {
        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessage('not enabled');
        (new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY))->searchByPath('/some/movie.mkv');
    }

    public function test_download_throws_before_onenable(): void
    {
        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessage('not enabled');
        (new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY))->download(12345);
    }

    // ---------------------------------------------------------------------
    // Search request shape
    // ---------------------------------------------------------------------

    public function test_search_by_imdb_id_requests_the_correct_v1_subtitles_url(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [self::response(200, '{"data":[]}')], $history);

        $provider->onEnable($this->stubContainer());
        $provider->searchByImdbId('tt1234567');

        $this->assertCount(1, $history);
        $this->assertSame('GET', $history[0]['method']);
        $this->assertStringStartsWith(
            'https://api.opensubtitles.com/api/v1/subtitles',
            $history[0]['url'],
        );
        $this->assertStringContainsString('imdb_id=tt1234567', $history[0]['url']);
    }

    /**
     * The hash search must send BOTH the `moviehash` and the file size
     * (`moviebytesize`). Mutating the query to drop the size, or to send the
     * weak filename query instead, must fail this.
     */
    public function test_search_by_hash_sends_moviehash_and_filesize(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [self::response(200, '{"data":[]}')], $history);

        $provider->onEnable($this->stubContainer());
        $provider->searchByHash('8e245d9679d31e12', 12909756);

        $this->assertCount(1, $history);
        $this->assertStringContainsString('moviehash=8e245d9679d31e12', $history[0]['url']);
        $this->assertStringContainsString('moviebytesize=12909756', $history[0]['url']);
    }

    /**
     * searchByPath computes the moviehash from the on-disk file and issues a
     * hash search carrying that exact hash + size — proving the file-path search
     * uses the real hash, not a filename fallback.
     */
    public function test_search_by_path_computes_and_sends_the_file_moviehash(): void
    {
        $file = $this->makeFixture(str_repeat("\0", 131072));
        $expectedHash = OpenSubtitlesProvider::computeMovieHash($file);

        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        // Return one result so the hash search is considered a hit (no fallback).
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'data' => [[
                    'id' => '1',
                    'attributes' => [
                        'language' => 'en',
                        'download_count' => 1,
                        'files' => [['file_id' => 1, 'cd_number' => 1, 'file_name' => 'a.srt']],
                    ],
                ]],
            ])),
        ], $history);

        try {
            $provider->onEnable($this->stubContainer());
            $results = $provider->searchByPath($file);
        } finally {
            unlink($file);
        }

        $this->assertCount(1, $history);
        $this->assertStringContainsString('moviehash=' . $expectedHash, $history[0]['url']);
        $this->assertStringContainsString('moviebytesize=131072', $history[0]['url']);
        $this->assertCount(1, $results);
    }

    /**
     * When the file cannot be hashed (e.g. it does not exist), searchByPath
     * falls back to a filename-based query rather than a hash search.
     */
    public function test_search_by_path_falls_back_to_filename_when_hash_unavailable(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [self::response(200, '{"data":[]}')], $history);

        $provider->onEnable($this->stubContainer());
        $provider->searchByPath('/nonexistent/Movie.Name.2019.mkv');

        $this->assertCount(1, $history);
        $this->assertStringNotContainsString('moviehash=', $history[0]['url']);
        $this->assertStringContainsString('query=', $history[0]['url']);
    }

    // ---------------------------------------------------------------------
    // Search response parsing (JSON:API shape)
    // ---------------------------------------------------------------------

    public function test_search_parses_attributes_files_array_and_surfaces_file_id(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'data' => [[
                    'id' => '3634077',
                    'type' => 'subtitle',
                    'attributes' => [
                        'language' => 'en',
                        'download_count' => 5000,
                        'feature_details' => ['imdb_id' => 1234567],
                        'files' => [['file_id' => 998877, 'cd_number' => 1, 'file_name' => 'Movie.Name.2019.srt']],
                    ],
                ]],
            ])),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $results = $provider->searchByImdbId('tt1234567');

        $this->assertCount(1, $results);
        $this->assertSame('3634077', $results[0]['id']);
        $this->assertSame('en', $results[0]['language']);
        $this->assertSame('srt', $results[0]['format']);
        $this->assertSame(5000, $results[0]['downloads']);
        $this->assertSame('Movie.Name.2019.srt', $results[0]['filename']);
        $this->assertSame('tt1234567', $results[0]['imdb_id']);
        $this->assertSame(998877, $results[0]['file_id']);
        $this->assertSame(
            [['file_id' => 998877, 'file_name' => 'Movie.Name.2019.srt', 'cd_number' => 1]],
            $results[0]['files'],
        );
    }

    public function test_search_normalizes_numeric_imdb_id_with_zero_padding(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'data' => [[
                    'id' => '1',
                    'attributes' => [
                        'language' => 'en',
                        'download_count' => 1,
                        'feature_details' => ['imdb_id' => 133093],
                        'files' => [['file_id' => 1, 'cd_number' => 1, 'file_name' => 'a.srt']],
                    ],
                ]],
            ])),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $results = $provider->searchByImdbId('tt0133093');

        $this->assertSame('tt0133093', $results[0]['imdb_id']);
    }

    public function test_search_returns_null_imdb_id_when_missing_or_invalid(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'data' => [
                    [
                        'id' => '1',
                        'attributes' => [
                            'language' => 'en',
                            'download_count' => 1,
                            'files' => [['file_id' => 1, 'cd_number' => 1, 'file_name' => 'a.srt']],
                        ],
                    ],
                    [
                        'id' => '2',
                        'attributes' => [
                            'language' => 'en',
                            'download_count' => 1,
                            'feature_details' => ['imdb_id' => 'not-a-number'],
                            'files' => [['file_id' => 2, 'cd_number' => 1, 'file_name' => 'b.srt']],
                        ],
                    ],
                ],
            ])),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $results = $provider->searchByImdbId('tt1234567');

        $this->assertCount(2, $results);
        $this->assertNull($results[0]['imdb_id']);
        $this->assertNull($results[1]['imdb_id']);
    }

    public function test_search_preserves_every_file_in_a_multi_cd_release(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'data' => [[
                    'id' => '3634078',
                    'attributes' => [
                        'language' => 'en',
                        'download_count' => 200,
                        'feature_details' => ['imdb_id' => 1234567],
                        'files' => [
                            ['file_id' => 111111, 'cd_number' => 1, 'file_name' => 'Movie.Name.2019.CD1.srt'],
                            ['file_id' => 111112, 'cd_number' => 2, 'file_name' => 'Movie.Name.2019.CD2.srt'],
                        ],
                    ],
                ]],
            ])),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $results = $provider->searchByImdbId('tt1234567');

        $this->assertCount(1, $results);
        $this->assertSame(111111, $results[0]['file_id']);
        $this->assertCount(2, $results[0]['files']);
        $this->assertSame(111112, $results[0]['files'][1]['file_id']);
    }

    public function test_search_skips_subtitles_with_no_downloadable_files(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'data' => [
                    ['id' => '1', 'attributes' => ['language' => 'en', 'download_count' => 5000, 'files' => [
                        ['file_id' => 998877, 'cd_number' => 1, 'file_name' => 'a.srt'],
                    ]]],
                    ['id' => '2', 'attributes' => ['language' => 'en', 'download_count' => 99999, 'files' => []]],
                    ['id' => '3', 'attributes' => ['language' => 'en', 'download_count' => 1, 'files' => [
                        ['file_id' => 555, 'cd_number' => 1, 'file_name' => 'b.srt'],
                    ]]],
                ],
            ])),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $results = $provider->searchByImdbId('tt1234567');

        $this->assertCount(2, $results);
        $this->assertSame(['1', '3'], array_column($results, 'id'));
    }

    public function test_search_throws_on_error_status(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [self::response(503, 'upstream down')], $history);

        $provider->onEnable($this->stubContainer());

        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessage('Search by IMDB ID failed');
        $provider->searchByImdbId('tt1234567');
    }

    // ---------------------------------------------------------------------
    // Download two-step flow
    // ---------------------------------------------------------------------

    public function test_search_result_file_id_round_trips_into_download(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY, format: 'srt');
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'data' => [[
                    'id' => '3634078',
                    'attributes' => [
                        'language' => 'en',
                        'download_count' => 200,
                        'files' => [
                            ['file_id' => 111111, 'cd_number' => 1, 'file_name' => 'CD1.srt'],
                            ['file_id' => 111112, 'cd_number' => 2, 'file_name' => 'CD2.srt'],
                        ],
                    ],
                ]],
            ])),
            self::response(200, (string) json_encode([
                'link' => 'https://dl.opensubtitles.org/download/src/upload/111112.srt',
                'file_name' => 'CD2.srt',
            ])),
            self::response(200, "1\n00:00:01,000 --> 00:00:02,000\nCD two.\n"),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $results = $provider->searchByImdbId('tt1234567');

        $secondCdFileId = $results[0]['files'][1]['file_id'];
        $this->assertSame(111112, $secondCdFileId);

        $download = $provider->download($secondCdFileId);

        $this->assertCount(3, $history);
        /** @var array<string, mixed> $requestBody */
        $requestBody = json_decode((string) $history[1]['body'], true);
        $this->assertSame(['file_id' => 111112, 'sub_format' => 'srt'], $requestBody);
        $this->assertSame("1\n00:00:01,000 --> 00:00:02,000\nCD two.\n", $download->content);
        $this->assertSame('CD2.srt', $download->fileName);
    }

    public function test_download_posts_file_id_and_fetches_content_from_link(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY, format: 'srt');
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'link' => 'https://dl.opensubtitles.org/download/src/upload/12345.srt',
                'file_name' => 'Movie.Name.2019.srt',
                'requests' => 1,
                'remaining' => 4,
                'message' => 'Your quota will be renewed in 2 hours and 47 minutes',
                'reset_time' => '2 hours and 47 minutes',
                'reset_time_utc' => '2026-07-13T23:59:59Z',
            ])),
            self::response(200, "1\n00:00:01,000 --> 00:00:02,000\nHello world.\n"),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $download = $provider->download(998877);

        $this->assertCount(2, $history);

        $this->assertSame('POST', $history[0]['method']);
        $this->assertSame('https://api.opensubtitles.com/api/v1/download', $history[0]['url']);
        /** @var array<string, mixed> $requestBody */
        $requestBody = json_decode((string) $history[0]['body'], true);
        $this->assertSame(['file_id' => 998877, 'sub_format' => 'srt'], $requestBody);

        $this->assertSame('GET', $history[1]['method']);
        $this->assertSame('https://dl.opensubtitles.org/download/src/upload/12345.srt', $history[1]['url']);

        $this->assertSame("1\n00:00:01,000 --> 00:00:02,000\nHello world.\n", $download->content);
        $this->assertSame('srt', $download->format);
        $this->assertSame('Movie.Name.2019.srt', $download->fileName);
        $this->assertSame(1, $download->requestsUsed);
        $this->assertSame(4, $download->downloadsRemaining);
        $this->assertSame('Your quota will be renewed in 2 hours and 47 minutes', $download->quotaMessage);
        $this->assertSame('2 hours and 47 minutes', $download->resetTime);
        $this->assertSame('2026-07-13T23:59:59Z', $download->resetTimeUtc);
    }

    public function test_download_posts_to_the_correct_v1_download_url(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'link' => 'https://dl.opensubtitles.org/download/src/upload/12345.srt',
                'file_name' => 'movie.srt',
            ])),
            self::response(200, 'subtitle body'),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $provider->download(998877);

        $this->assertSame('POST', $history[0]['method']);
        $this->assertSame('https://api.opensubtitles.com/api/v1/download', $history[0]['url']);
    }

    public function test_download_throws_when_response_is_missing_link(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode(['file_name' => 'movie.srt'])),
        ], $history);

        $provider->onEnable($this->stubContainer());

        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessage('did not include a download link');
        $provider->download(998877);
    }

    public function test_download_throws_quota_exceeded_when_no_link_and_zero_remaining(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'remaining' => 0,
                'message' => 'You have downloaded your allowed 20 subtitles for 24h.'
                    . 'Your quota will be renewed in 00 hours and 27 minutes (2023-05-24 23:59:59 UTC) ',
                'reset_time_utc' => '2023-05-24T23:59:59Z',
            ])),
        ], $history);

        $provider->onEnable($this->stubContainer());

        try {
            $provider->download(998877);
            $this->fail('Expected OpenSubtitlesQuotaExceededException to be thrown.');
        } catch (OpenSubtitlesQuotaExceededException $e) {
            $this->assertStringContainsString('allowed 20 subtitles for 24h', $e->getMessage());
            $this->assertSame('2023-05-24T23:59:59Z', $e->resetTimeUtc);
        }
    }

    public function test_download_throws_quota_exceeded_when_post_returns_4xx_quota_message(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(406, (string) json_encode([
                'message' => 'You have downloaded your allowed 20 subtitles for 24h.'
                    . 'Your quota will be renewed in 00 hours and 27 minutes (2023-05-24 23:59:59 UTC) ',
            ])),
        ], $history);

        $provider->onEnable($this->stubContainer());

        try {
            $provider->download(998877);
            $this->fail('Expected OpenSubtitlesQuotaExceededException to be thrown.');
        } catch (OpenSubtitlesQuotaExceededException $e) {
            $this->assertStringContainsString('allowed 20 subtitles for 24h', $e->getMessage());
        }
    }

    public function test_download_does_not_report_quota_exceeded_for_unrelated_4xx(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(401, (string) json_encode(['message' => 'Unauthorized'])),
        ], $history);

        $provider->onEnable($this->stubContainer());

        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessageMatches('/Download failed/');

        try {
            $provider->download(998877);
        } catch (OpenSubtitlesException $e) {
            $this->assertNotInstanceOf(OpenSubtitlesQuotaExceededException::class, $e);
            throw $e;
        }
    }

    public function test_download_quota_exceeded_uses_fallback_message_when_api_omits_message(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode(['remaining' => 0])),
        ], $history);

        $provider->onEnable($this->stubContainer());

        try {
            $provider->download(998877);
            $this->fail('Expected OpenSubtitlesQuotaExceededException to be thrown.');
        } catch (OpenSubtitlesQuotaExceededException $e) {
            $this->assertStringContainsString('quota exceeded', $e->getMessage());
            $this->assertNull($e->resetTime);
            $this->assertNull($e->resetTimeUtc);
        }
    }

    public function test_download_throws_when_content_fetch_fails(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);
        $history = [];
        $this->installFakeTransport($provider, [
            self::response(200, (string) json_encode([
                'link' => 'https://dl.opensubtitles.org/download/src/upload/12345.srt',
                'file_name' => 'movie.srt',
            ])),
            self::response(404, 'Not Found'),
        ], $history);

        $provider->onEnable($this->stubContainer());

        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessageMatches('/Download failed/');
        $provider->download(998877);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * @return array{status:int, body:string}
     */
    private static function response(int $status, string $body): array
    {
        return ['status' => $status, 'body' => $body];
    }

    /**
     * Install a fake transport closure into the provider that records outgoing
     * requests into $history and returns queued {status, body} responses.
     *
     * $history MUST be passed by reference — the closure binds it by reference
     * so requests appended during the call are visible to the caller afterwards.
     *
     * @param list<array{status:int, body:string}>                                   $responses
     * @param list<array{method:string, url:string, headers:array<string,string>, body:?string}> $history
     */
    private function installFakeTransport(OpenSubtitlesProvider $provider, array $responses, array &$history): void
    {
        $queue = $responses;
        $transport = function (string $method, string $url, array $headers, ?string $body) use (&$queue, &$history): array {
            $history[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
            if ($queue === []) {
                throw new \RuntimeException("FakeTransport: no queued response for {$method} {$url}");
            }

            return array_shift($queue);
        };

        $property = new \ReflectionProperty(OpenSubtitlesProvider::class, 'transport');
        $property->setAccessible(true);
        $property->setValue($provider, $transport);
    }

    /**
     * Write $content to a temp file and return its path.
     */
    private function makeFixture(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'phlix_os_');
        if ($path === false) {
            $this->fail('Could not create temp fixture file');
        }
        file_put_contents($path, $content);

        return $path;
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
