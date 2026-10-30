# phlix-plugin-opensubtitles

[![tests](https://github.com/detain/phlix-plugin-opensubtitles/actions/workflows/test.yml/badge.svg)](https://github.com/detain/phlix-plugin-opensubtitles/actions/workflows/test.yml)

> OpenSubtitles **subtitle-provider** plugin for [Phlix](https://github.com/detain/phlix)
> — search and download subtitles for movies and TV shows via the OpenSubtitles REST API.

## What it does

This plugin integrates [OpenSubtitles](https://www.opensubtitles.com/) into the Phlix subtitle pipeline. It allows searching for subtitles by:

- **IMDB ID** — find subtitles for a specific movie/TV show
- **Filename** — extract media info from a filename and search matching subtitles
- **File hash** — match subtitles using the OpenSubtitles `moviehash` algorithm

## Install

The plugin is unsigned by design. Install via the Phlix admin UI:

1. Log in to your Phlix server as an admin user (`users.is_admin = 1`).
2. Browse to `/admin/plugins`.
3. Paste this URL into the **Install from URL** form and submit:

   ```
   https://raw.githubusercontent.com/detain/phlix-plugin-opensubtitles/main/plugin.json
   ```

4. Configure your OpenSubtitles API key in the plugin settings.

## Configuration

Configure these in the Phlix admin **Plugins → Configure** dialog.

| Setting      | Required | Secret | Default | Description                                      |
|--------------|----------|--------|---------|--------------------------------------------------|
| `api_key`    | **Yes**  | yes    | —       | API key for your OpenSubtitles.com consumer (API application). |
| `username`   | No       | —      | —       | OpenSubtitles.com username. Raises download limits (VIP limits for VIP accounts). |
| `password`   | No       | yes    | —       | OpenSubtitles.com password. Only used with the username. |
| `language`   | No       | —      | `en`    | Default subtitle language (ISO 639-1, e.g. en, es, fr). |
| `format`     | No       | —      | `srt`   | Preferred subtitle format (srt, sub, ass, etc.). |
| `priority`   | No       | —      | `10`    | Ranking weight for this subtitle source relative to others. Lower runs first (0 = highest priority); admins can override the effective order via the server subtitle-provider priority list. |

### Where to get your API key

Create a **consumer** (API application) on OpenSubtitles.com and copy its API key:
[opensubtitles.com/en/consumers](https://www.opensubtitles.com/en/consumers). Providing your
account `username`/`password` as well is optional but raises your download quota.

## How it works

The plugin implements the `Phlix\Shared\Plugin\LifecycleInterface`, `Phlix\Shared\Plugin\ConfigurableInterface`, and `Phlix\Shared\Subtitle\SubtitleSourceInterface` contracts and connects to the OpenSubtitles REST API v1:

1. **Login — lazy and conditional** — nothing authenticates at install, enable,
   or boot. On the first search/download with `username`/`password` configured,
   the plugin obtains a session token (`POST /login`) and sends it as
   `Authorization: Bearer <token>` on API-origin requests. The token is
   refreshed when it reaches its advertised expiry or the server revokes it (a
   mid-session HTTP 401 costs exactly one refresh-and-retry, never a loop); a
   confirmed credential rejection suppresses further attempts for the enable
   cycle. Without username/password the plugin runs anonymously on the
   `api_key` alone — lower download limits, full search. The REST v1 API has
   **no user-agent registration endpoint**; see “User-Agent & OpenSubtitles
   ToS” below for what actually satisfies the terms.
2. **Search** — queries subtitles by IMDB ID, filename, or `moviehash`.
3. **Download — two steps** — `POST /download` with the file id consumes ONE
   quota unit and returns a temporary HTTPS link plus quota accounting; the
   plugin then fetches that link with a plain, **credential-free HTTPS GET**
   (the consumer key and session token never leave the API origin, and
   non-HTTPS links are refused). Because step 1 meters the quota per call, a
   file is posted for at most once per intentional request — never loop
   `download()` speculatively, and treat the quota exception below as "stop
   for this window".

When the account's download quota is exhausted, the shared-contract entry
point `download()` throws `Phlix\Shared\Subtitle\Exception\QuotaExceeded` —
carrying the remaining allowance and the reset time — so callers can stop
retrying or fall back to another provider. `QuotaExceeded` extends
`RuntimeException`, NOT `OpenSubtitlesException`; catching the plugin's own
exception base around `download()` would miss it. The low-level `downloadRaw()`
signals the same condition as `OpenSubtitlesQuotaExceededException` (a subclass
of `OpenSubtitlesException`) — the type `download()` translates from. Once the
API reports an exhausted window (`remaining: 0` with a future
`reset_time_utc`), further downloads are refused locally with the same
boundary types until that reset — the plugin never re-dials a window the
vendor declared dead.

## User-Agent & OpenSubtitles ToS

Every request this plugin makes — API origin and download-link hosts alike —
carries the descriptive, contact-bearing `User-Agent` that OpenSubtitles'
terms require:

```text
Phlix-Plugin-OpenSubtitles/<plugin version> (+https://github.com/detain/phlix-plugin-opensubtitles)
```

The version comes from `plugin.json`; the contact route is the
`USER_AGENT_CONTACT_URL` constant in the provider. The REST v1 API performs
no user-agent registration handshake — the header itself IS the disclosure,
and it is the vendor's path back to the maintainer. Non-descriptive or
contact-less agents get the consumer key rate-limited or banned, and a ban
lands on every Phlix instance sharing the build. **Forks and derivatives MUST
replace the product name and `USER_AGENT_CONTACT_URL` with their own identity
and contact route before publishing.**

API references: [REST API docs](https://www.opensubtitles.com/en/doc/apis) ·
[consumer (API application) portal](https://www.opensubtitles.com/en/consumers).

## Development

Setup (PHP >= 8.3). `composer.lock` is committed on purpose — CI audits
exactly those pinned versions (the S440 security-audit gate reads the
tracked lock), so install from it rather than re-resolving:

    composer install

Local gates (the same commands `.github/workflows/test.yml` runs):

    vendor/bin/phpunit                           # hermetic unit suite (fake transport, no network)
    vendor/bin/phpstan analyse src --level=9     # or: composer phpstan
    vendor/bin/phpcs src --standard=PSR12        # or: composer phpcs

There is deliberately no `composer test` script; run `vendor/bin/phpunit`
directly. PSR-12 is gated on `src/` only: `tests/` follows the repo's
snake_case test-method convention, which the raw PSR-12 sniff reports on
(a pre-existing backlog) — keep new test code consistent with its
neighbors rather than re-casing it.

## License

MIT — see [`LICENSE`](LICENSE).
