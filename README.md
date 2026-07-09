# phlix-plugin-opensubtitles

[![tests](https://github.com/detain/phlix-plugin-opensubtitles/actions/workflows/test.yml/badge.svg)](https://github.com/detain/phlix-plugin-opensubtitles/actions/workflows/test.yml)

> OpenSubtitles **subtitle-provider** plugin for [Phlix](https://github.com/detain/phlix)
> — search and download subtitles for movies and TV shows via the OpenSubtitles REST API.

## What it does

This plugin integrates [OpenSubtitles](https://www.opensubtitles.com/) into the Phlix subtitle pipeline. It allows searching for subtitles by:

- **IMDB ID** — find subtitles for a specific movie/TV show
- **Filename** — extract media info from a filename and search matching subtitles
- **File hash** — match subtitles using the OpenSubtitles hash algorithm

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

| Setting      | Required | Default | Description                                      |
|--------------|----------|---------|--------------------------------------------------|
| `api_key`    | Yes      | —       | OpenSubtitles API key (user agent token)         |
| `username`   | No       | —       | OpenSubtitles username (for logged-in downloads)  |
| `password`   | No       | —       | OpenSubtitles password (for logged-in downloads) |
| `language`   | No       | `en`    | Default subtitle language (ISO 639-1)            |
| `format`     | No       | `srt`   | Preferred subtitle format (srt, sub, ass, etc.)   |

## How it works

The plugin implements the `Phlix\Shared\Plugin\LifecycleInterface` contract and connects to the OpenSubtitles REST API v2:

1. **Login** — registers a user agent and obtains a session token
2. **Search** — queries subtitles by IMDB ID, filename, or hash
3. **Download** — fetches the subtitle file and feeds it into Phlix's subtitle pipeline

## API

The OpenSubtitles REST API is documented at [opensubtitles.com](https://www.opensubtitles.com/).

## License

MIT — see [`LICENSE`](LICENSE).
