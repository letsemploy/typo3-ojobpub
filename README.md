# oJobPub for TYPO3

Publishes a site's job openings as an [oJobPub](https://docs.letsemploy.org) feed at

```
https://example.com/.well-known/ojobpub.json
```

Each entry is a short, structured summary that links to the job page on your site, where the full text and the application process stay. Job boards, search engines and tools read the feed directly, or through the aggregated data of [SourceTracker](https://docs.letsemploy.org/resources/sourcetracker/).

> **Status: alpha scaffold.** The feed core is tested; the TYPO3 integration has not run in an installation yet. See `CLAUDE.md`.

Requirements: TYPO3 13.4 LTS or 14.3 LTS, PHP 8.2+.

## Installation

```
composer require letsemploy/typo3-ojobpub
vendor/bin/typo3 extension:setup
```

## Configuration

1. Add the site set **oJobPub feed** (`letsemploy/ojobpub`) to your site (Site Management → Sites → Sets).
2. In Site Management → Settings → oJobPub, set the **employer name** and the **storage folders** of your job records, then switch on **Serve /.well-known/ojobpub.json**. The site base must be an absolute URL (`https://example.com/`).
3. Create job records (List module → *Job opening*) in a storage folder. Each job needs a title, job type and a job page (a page in the site or an external URL; alternatively set a detail page in the settings).

| Setting | Default | |
| --- | --- | --- |
| `ojobpub.enabled` | `false` | Serve the feed; switch on once the employer is set |
| `ojobpub.source` | `native` | Where jobs come from (other extensions can add sources) |
| `ojobpub.employer.name` | | Required |
| `ojobpub.employer.city`, `.country`, `.industry`, `.url` | | `country` as ISO 3166-1 alpha-2, e.g. `CH`; `url` defaults to the site base |
| `ojobpub.defaults.country` | employer country | For locations without a country |
| `ojobpub.native.storagePids` | | Comma-separated page IDs |
| `ojobpub.native.detailPid` | `0` | Fallback job page |
| `ojobpub.cacheLifetime` | `3600` | Seconds; the feed is also rebuilt after every change of a job record and at midnight |

Only published records of the live workspace are included. Jobs whose application deadline has passed are left out automatically. Jobs that cannot be published (e.g. no job page) are left out too; list them with:

```
vendor/bin/typo3 ojobpub:feed <site-identifier> --issues
```

## Web server

The feed must be reachable on the **apex domain** (`example.com`). A redirect from `example.com` to `www.example.com` that keeps the path is fine.

- Apache with TYPO3's default `.htaccess`: nothing to do; `/.well-known/ojobpub.json` is passed to `index.php`. That `.htaccess` removes `ETag` headers; conditional requests still work through `Last-Modified` / `If-Modified-Since`.
- Nginx: many configurations deny all dot paths (`location ~ /\. { deny all; }`). Exclude `.well-known`, e.g. `location ~ /\.(?!well-known/) { deny all; }`, and make sure `/.well-known/` falls through to `index.php` (`try_files $uri /index.php$is_args$args;`).
- If something else already serves `/.well-known/` from disk (e.g. ACME challenges), only `ojobpub.json` must reach TYPO3.

Check it:

```
curl -sSL -D- https://example.com/.well-known/ojobpub.json -o /dev/null
```

and validate with the [online validator](https://validator.letsemploy.org). Then [register your feed](https://docs.letsemploy.org/publishing/register/).

## For developers

Other extensions can provide jobs by implementing `LetsEmploy\OJobPub\Source\SourceInterface`; the class is registered automatically. Select it with the site setting `ojobpub.source`. Return raw arrays with oJobPub field names; validation and cleanup happen in the extension's Normalizer.

Local TYPO3 v14.3 and v13.4 with sample jobs (podman and podman-compose; `COMPOSE=docker-compose CONTAINER=docker` for Docker):

```
make up setup seed            # TYPO3 14 on http://localhost:8014 (admin / Password1!)
make up setup seed TYPO3=13   # TYPO3 13 on http://localhost:8013
make feed                     # print the feed
make issues                   # jobs left out and why
make validate-all             # both versions: install, seed, validate against ../schema
```

More in `CLAUDE.md`. Specification: https://docs.letsemploy.org/ojobpub/specification/

## License

GPL-2.0-or-later
