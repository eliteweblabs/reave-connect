# REΛVE Connect

WordPress™ plugin that lets [REΛVE](https://reave.app) manage a site remotely — posts, pages, media, plugins, cache, and options. Auto-updates from `https://reave.app/api/wp-update/reave-connect/`.

This is the only WordPress companion. The older **Reave Bridge** plugin is retired.

## Install

1. Download [`reave-connect.zip`](https://github.com/eliteweblabs/reave-connect/releases/latest/download/reave-connect.zip) from [Releases](https://github.com/eliteweblabs/reave-connect/releases/latest)
2. WP Admin → Plugins → Add New → Upload Plugin
3. Activate, then Settings → Reave Connect
4. Paste the same API key as `REAVE_WP_API_KEY` on the REΛVE install

## Enable on a REΛVE install

Super admin (deployment owner) turns on **WordPress™ Connect** in Admin → Add-ons (`wordpress_content`). Set `REAVE_WP_API_KEY` (and optional `REAVE_WP_SITE_URL`) on the Railway app service.

## REST

- `GET /wp-json/reave/v1/status` — header `X-Reave-Key`
- `POST /wp-json/reave/v1/exec` — `{ "action": "…", "params": {} }`

## License

GPL-2.0+
