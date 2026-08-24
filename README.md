# REΛVE Connect

WordPress™ plugin that lets [REΛVE](https://reave.app) manage a site remotely — posts, pages, media, menus, redirects, plugins, cache, and options. Auto-updates from `https://reave.app/api/wp-update/reave-connect/`.

This is the only WordPress companion. The older **Reave Bridge** plugin is retired; every Bridge capability now lives here as an `/exec` action.

## Install

1. Download [`reave-connect.zip`](https://github.com/eliteweblabs/reave-connect/releases/latest/download/reave-connect.zip) from [Releases](https://github.com/eliteweblabs/reave-connect/releases/latest)
2. WP Admin → Plugins → Add New → Upload Plugin
3. Activate, then Settings → Reave Connect
4. Paste the same API key as `REAVE_WP_API_KEY` on the REΛVE install

## Enable on a REΛVE install

Deployment owners request or toggle **WordPress™ Connect** in Admin → Add-ons (`wordpress_content`). Set `REAVE_WP_API_KEY` (and optional `REAVE_WP_SITE_URL`) on the Railway app service.

## REST

- `GET /wp-json/reave/v1/status` — header `X-Reave-Key`
- `POST /wp-json/reave/v1/exec` — `{ "action": "…", "params": {} }`

### Exec actions

Site: `status`, `site_info`, `health`, `get_indexing_status`, `enable_indexing`, `disable_indexing`, `list_plugins`, `activate_plugin`, `deactivate_plugin`, `install_plugin`, `get_active_theme`, `get_option`, `update_option`, `flush_cache`, `flush_rewrite`, `search_replace` (`dry_run` defaults true)

Content: `list_content`, `get_content`, `create_content`, `update_content`, `delete_content`, `get_post_meta`, `update_post_meta`, `list_media`, `get_media`, `upload_media`, `set_featured_image`

Menus: `list_menus`, `get_menu_items`, `update_menu_item`

Redirects: `list_redirects`, `create_redirect`, `delete_redirect` (uses the Redirection plugin when present)

Auth salts, `reave_api_key`, and similar option keys are blocked.

## License

GPL-2.0+
