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
     * Regression test for the `filterSubtitles()` shape bug: it used to read
     * `attributes.file` (singular, never present in the real API), so every
     * result's `format`/`filename` silently fell back to a fabricated generic
     * value and no `file_id` was ever surfaced at all — meaning a caller had no
     * way to feed a real search result into {@see OpenSubtitlesProvider::download()}.
     * The real API nests file info under `attributes.files` (an array), each with
     * `file_id`, `file_name`, `cd_number`; `imdb_id` lives under
     * `attributes.feature_details.imdb_id` as a JSON NUMBER (not a string — see
     * {@see OpenSubtitlesProvider::normalizeImdbId()}); and the JSON:API resource
     * `id` is a STRING, not an int.
     *
     * The `imdb_id` fixture below is deliberately the bare int `1234567` (the real
     * API shape), NOT the string `"tt1234567"` — encoding the fixture as a string
     * previously let `is_string($featureDetails['imdb_id'])` pass against a fake
     * shape while silently returning `null` against every real response.
     */
    public function test_search_by_imdb_id_parses_attributes_files_array_and_surfaces_file_id(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        $history = [];
        $this->installMockHttpClient($provider, [
            new Response(200, [], (string) json_encode([
                'data' => [
                    [
                        'id' => '3634077',
                        'type' => 'subtitle',
                        'attributes' => [
                            'language' => 'en',
                            'download_count' => 5000,
                            'feature_details' => [
                                'imdb_id' => 1234567,
                            ],
                            'files' => [
                                [
                                    'file_id' => 998877,
                                    'cd_number' => 1,
                                    'file_name' => 'Movie.Name.2019.srt',
                                ],
                            ],
                        ],
                    ],
                ],
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

    /**
     * Dedicated regression test for the `imdb_id` TYPE bug (as opposed to the
     * path bug covered above): the real API returns `feature_details.imdb_id` as
     * a JSON number, e.g. `133093` for `tt0133093` (leading zeros are not
     * representable in a JSON number, so the raw value drops them). A prior fix
     * corrected the JSON path but kept an `is_string()` check, so `imdb_id` stayed
     * `null` for every real (numeric) response even after that fix. This asserts
     * the numeric value is both extracted AND re-normalized to the `tt`-prefixed,
     * zero-padded string convention used elsewhere in Phlix (see
     * `phlix-server`'s `ImdbLookup`/`TmdbProvider`/`MovieMetadataResolver`).
     */
    public function test_search_by_imdb_id_normalizes_numeric_imdb_id_with_zero_padding(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        $history = [];
        $this->installMockHttpClient($provider, [
            new Response(200, [], (string) json_encode([
                'data' => [
                    [
                        'id' => '1',
                        'attributes' => [
                            'language' => 'en',
                            'download_count' => 1,
                            'feature_details' => ['imdb_id' => 133093],
                            'files' => [
                                ['file_id' => 1, 'cd_number' => 1, 'file_name' => 'a.srt'],
                            ],
                        ],
                    ],
                ],
            ])),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $results = $provider->searchByImdbId('tt0133093');

        $this->assertCount(1, $results);
        $this->assertSame('tt0133093', $results[0]['imdb_id']);
    }

    /**
     * When `feature_details.imdb_id` is absent, or is a shape that isn't a real
     * IMDB id at all (a non-numeric string, `null`, or a JSON bool), `imdb_id`
     * must resolve to `null` rather than throwing or fabricating a placeholder.
     */
    public function test_search_by_imdb_id_returns_null_imdb_id_when_missing_or_invalid(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        $history = [];
        $this->installMockHttpClient($provider, [
            new Response(200, [], (string) json_encode([
                'data' => [
                    [
                        'id' => '1',
                        'attributes' => [
                            'language' => 'en',
                            'download_count' => 1,
                            'files' => [
                                ['file_id' => 1, 'cd_number' => 1, 'file_name' => 'a.srt'],
                            ],
                        ],
                    ],
                    [
                        'id' => '2',
                        'attributes' => [
                            'language' => 'en',
                            'download_count' => 1,
                            'feature_details' => ['imdb_id' => 'not-a-number'],
                            'files' => [
                                ['file_id' => 2, 'cd_number' => 1, 'file_name' => 'b.srt'],
                            ],
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

    /**
     * A single subtitle listing can carry multiple files (e.g. one file per CD
     * for old multi-CD releases). Naively taking `files[0]` and discarding the
     * rest would silently misrepresent the release as a single-file download —
     * this asserts every file survives in the `files` sub-array, not just the
     * first, while `file_id` still conveniently aliases the first file.
     */
    public function test_search_by_imdb_id_preserves_every_file_in_a_multi_cd_release(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        $history = [];
        $this->installMockHttpClient($provider, [
            new Response(200, [], (string) json_encode([
                'data' => [
                    [
                        'id' => '3634078',
                        'attributes' => [
                            'language' => 'en',
                            'download_count' => 200,
                            'feature_details' => ['imdb_id' => 1234567],
                            'files' => [
                                [
                                    'file_id' => 111111,
                                    'cd_number' => 1,
                                    'file_name' => 'Movie.Name.2019.CD1.srt',
                                ],
                                [
                                    'file_id' => 111112,
                                    'cd_number' => 2,
                                    'file_name' => 'Movie.Name.2019.CD2.srt',
                                ],
                            ],
                        ],
                    ],
                ],
            ])),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $results = $provider->searchByImdbId('tt1234567');

        $this->assertCount(1, $results);
        $this->assertSame(111111, $results[0]['file_id']);
        $this->assertCount(2, $results[0]['files']);
        $this->assertSame(
            ['file_id' => 111111, 'file_name' => 'Movie.Name.2019.CD1.srt', 'cd_number' => 1],
            $results[0]['files'][0],
        );
        $this->assertSame(
            ['file_id' => 111112, 'file_name' => 'Movie.Name.2019.CD2.srt', 'cd_number' => 2],
            $results[0]['files'][1],
        );
    }

    /**
     * A subtitle entry with an empty (or missing) `files` array has no `file_id`
     * to ever pass to {@see OpenSubtitlesProvider::download()}, so it must be
     * dropped rather than surfaced with a fake sentinel `file_id`. This also
     * proves the multi-result sort-by-downloads still works once a dead entry
     * is filtered out from the middle of the list.
     */
    public function test_search_by_imdb_id_skips_subtitles_with_no_downloadable_files(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        $history = [];
        $this->installMockHttpClient($provider, [
            new Response(200, [], (string) json_encode([
                'data' => [
                    [
                        'id' => '1',
                        'attributes' => ['language' => 'en', 'download_count' => 5000, 'files' => [
                            ['file_id' => 998877, 'cd_number' => 1, 'file_name' => 'a.srt'],
                        ]],
                    ],
                    [
                        'id' => '2',
                        'attributes' => ['language' => 'en', 'download_count' => 99999, 'files' => []],
                    ],
                    [
                        'id' => '3',
                        'attributes' => ['language' => 'en', 'download_count' => 1, 'files' => [
                            ['file_id' => 555, 'cd_number' => 1, 'file_name' => 'b.srt'],
                        ]],
                    ],
                ],
            ])),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $results = $provider->searchByImdbId('tt1234567');

        $this->assertCount(2, $results);
        $this->assertSame(['1', '3'], array_column($results, 'id'));
    }

    /**
     * End-to-end proof that the fix actually wires search into download: a
     * `file_id` surfaced by the (now-fixed) search parsing — including a
     * non-first CD file, not just `files[0]` — round-trips correctly as the
     * `file_id` body param on the subsequent {@see download()} call.
     */
    public function test_search_result_file_id_round_trips_into_download(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY, format: 'srt');

        $history = [];
        $this->installMockHttpClient($provider, [
            new Response(200, [], (string) json_encode([
                'data' => [
                    [
                        'id' => '3634078',
                        'attributes' => [
                            'language' => 'en',
                            'download_count' => 200,
                            'files' => [
                                ['file_id' => 111111, 'cd_number' => 1, 'file_name' => 'CD1.srt'],
                                ['file_id' => 111112, 'cd_number' => 2, 'file_name' => 'CD2.srt'],
                            ],
                        ],
                    ],
                ],
            ])),
            new Response(200, [], (string) json_encode([
                'link' => 'https://dl.opensubtitles.org/download/src/upload/111112.srt',
                'file_name' => 'CD2.srt',
            ])),
            new Response(200, [], "1\n00:00:01,000 --> 00:00:02,000\nCD two.\n"),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $results = $provider->searchByImdbId('tt1234567');

        // Deliberately pick the SECOND CD's file_id, not the top-level convenience
        // alias, to prove the full `files[]` array — not just `files[0]` — is usable.
        $secondCdFileId = $results[0]['files'][1]['file_id'];
        $this->assertSame(111112, $secondCdFileId);

        $download = $provider->download($secondCdFileId);

        $this->assertCount(3, $history);
        $downloadRequest = $history[1]['request'];
        /** @var array<string, mixed> */
        $requestBody = json_decode((string) $downloadRequest->getBody(), true);
        $this->assertSame(['file_id' => 111112, 'sub_format' => 'srt'], $requestBody);
        $this->assertSame("1\n00:00:01,000 --> 00:00:02,000\nCD two.\n", $download->content);
        $this->assertSame('CD2.srt', $download->fileName);
    }

    /**
     * Regression test for the download API-shape bug: `download()` used to
     * `GET subtitles/{id}/download` expecting an inline base64 `content`
     * field in a single response. The real OpenSubtitles v1 API is a
     * two-step flow — `POST download` with a `file_id` body returns a
     * temporary `link` (plus quota accounting), and the actual subtitle
     * bytes must then be fetched with a second `GET` against that link.
     * This asserts both outgoing requests' shape AND that the final
     * returned content is the fetched file bytes, not the JSON envelope
     * from the first call.
     */
    public function test_download_posts_file_id_and_fetches_content_from_link(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY, format: 'srt');

        $history = [];
        $this->installMockHttpClient($provider, [
            new Response(200, [], (string) json_encode([
                'link' => 'https://dl.opensubtitles.org/download/src/upload/12345.srt',
                'file_name' => 'Movie.Name.2019.srt',
                'requests' => 1,
                'remaining' => 4,
                'message' => 'Your quota will be renewed in 2 hours and 47 minutes',
                'reset_time' => '2 hours and 47 minutes',
                'reset_time_utc' => '2026-07-13T23:59:59Z',
            ])),
            new Response(200, [], "1\n00:00:01,000 --> 00:00:02,000\nHello world.\n"),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $download = $provider->download(998877);

        $this->assertCount(2, $history);

        $linkRequest = $history[0]['request'];
        $this->assertSame('POST', $linkRequest->getMethod());
        $this->assertSame(
            'https://api.opensubtitles.com/api/v1/download',
            (string) $linkRequest->getUri(),
        );
        /** @var array<string, mixed> */
        $requestBody = json_decode((string) $linkRequest->getBody(), true);
        $this->assertSame(['file_id' => 998877, 'sub_format' => 'srt'], $requestBody);

        $contentRequest = $history[1]['request'];
        $this->assertSame('GET', $contentRequest->getMethod());
        $this->assertSame(
            'https://dl.opensubtitles.org/download/src/upload/12345.srt',
            (string) $contentRequest->getUri(),
        );

        $this->assertSame(
            "1\n00:00:01,000 --> 00:00:02,000\nHello world.\n",
            $download->content,
        );
        $this->assertSame('srt', $download->format);
        $this->assertSame('Movie.Name.2019.srt', $download->fileName);
        $this->assertSame(1, $download->requestsUsed);
        $this->assertSame(4, $download->downloadsRemaining);
        $this->assertSame('Your quota will be renewed in 2 hours and 47 minutes', $download->quotaMessage);
        $this->assertSame('2 hours and 47 minutes', $download->resetTime);
        $this->assertSame('2026-07-13T23:59:59Z', $download->resetTimeUtc);
    }

    /**
     * Dedicated regression test for the download endpoint's resolved URL, in the
     * same single-purpose style as {@see test_login_posts_to_the_correct_v1_login_url()}
     * and {@see test_search_by_imdb_id_requests_the_correct_v1_subtitles_url()}.
     *
     * Guzzle's `base_uri` + leading-slash request-path resolution (RFC 3986 §5.3)
     * previously produced 404s on `login` (see `OpenSubtitlesProvider::API_BASE`'s
     * docblock) because a leading slash on the request path replaces the entire
     * `base_uri` path component instead of merging with it. `download()`'s request
     * path (`'download'`, no leading slash) happens to already be correct as
     * written, but that fact was previously only ever asserted incidentally inside
     * a larger, multi-assertion test — nothing would have caught a future
     * leading-slash regression on this specific path in isolation. This asserts
     * the fully resolved absolute URI on its own, independent of the rest of the
     * download flow.
     */
    public function test_download_posts_to_the_correct_v1_download_url(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        $history = [];
        $this->installMockHttpClient($provider, [
            new Response(200, [], (string) json_encode([
                'link' => 'https://dl.opensubtitles.org/download/src/upload/12345.srt',
                'file_name' => 'movie.srt',
            ])),
            new Response(200, [], 'subtitle body'),
        ], $history);

        $provider->onEnable($this->stubContainer());
        $provider->download(998877);

        $this->assertGreaterThanOrEqual(1, count($history));
        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(
            'https://api.opensubtitles.com/api/v1/download',
            (string) $request->getUri(),
        );
    }

    /**
     * When the first-step response is missing `link` there is nothing to
     * fetch content from — this must fail loudly rather than return an
     * empty/placeholder subtitle.
     */
    public function test_download_throws_when_response_is_missing_link(): void
    {
        $provider = new OpenSubtitlesProvider(apiKey: self::TEST_API_KEY);

        $history = [];
        $this->installMockHttpClient($provider, [
            new Response(200, [], (string) json_encode(['file_name' => 'movie.srt'])),
        ], $history);

        $provider->onEnable($this->stubContainer());

        $this->expectException(OpenSubtitlesException::class);
        $this->expectExceptionMessage('did not include a download link');

        $provider->download(998877);
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
