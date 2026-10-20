# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

TYPO3 extension (key `ojobpub`, package `letsemploy/typo3-ojobpub`) that serves a site's job openings as an oJobPub 1.0 feed at `/.well-known/ojobpub.json`. Target: **TYPO3 v13.4 LTS and v14.3 LTS**, PHP 8.2+.

The format is defined by `../schema/v1/ojobpub.json` (sibling repo `letsemploy/schema`, JSON Schema 2020-12) and the spec in `../docs/docs/ojobpub/specification.md` (published at https://docs.letsemploy.org/ojobpub/specification/). The WordPress plugin in `../wordpress-plugin-ojobpub` is the **reference implementation**: when behaviour is unclear, do what it does (`includes/Feed/Normalizer.php`, `includes/Feed_Service.php`, `includes/Endpoint.php`) and keep both in sync.

## Status (scaffold, 2026-10-20)

| Part | State |
| --- | --- |
| `Classes/Feed/` (Normalizer, Builder, Json, Spec) | Implemented, unit tests pass, generated feeds validated against the schema |
| Middleware, FeedService, NativeSource, DataHandler hook, command | Written against TYPO3 v14.3.9 sources and reviewed against v13.4 (APIs, DI, middleware order resolved without cycles), `php -l` clean, **never run in TYPO3** |
| TCA, site set, ext_tables.sql | Written, reviewed against core sources, **never loaded**; check the TCA migration messages and DB compare first |
| CI (`.github/workflows/ci.yml`) | Lint + unit tests + schema validation, PHP 8.2–8.5 × TYPO3 13.4/14.3 plus a lowest-deps job, `composer audit`. Coding standards, PHPStan, functional tests and release still missing (Roadmap 8) |
| Dev environment, functional tests, detail plugin, backend UI | Missing, see Roadmap |

Treat everything marked "never run" as a draft that has to be proven in a real installation before building on it.

## Commands

Everything runs in containers (podman by default, `CONTAINER=docker` to override); no local PHP is needed.

```
make install            # composer install (phpunit, opis/json-schema)
make test               # unit tests; also validates generated documents against ../schema/v1/ojobpub.json if that repo is cloned next to this one
make test ARGS='--filter amountsAreParsed'
make lint               # php -l over all PHP files
```

Planned (Roadmap step 1), mirroring the WordPress plugin: `make up`, `make setup`, `make seed`, `make feed`, `make validate-all`.

Inside a TYPO3 installation:

```
vendor/bin/typo3 ojobpub:feed [site] [--issues] [--no-cache]   # print the feed, or the jobs left out and why
```

## Architecture

**Data flow:** `SourceInterface::jobs(Site)` → raw arrays keyed by a job key, using oJobPub field names → `Feed\Normalizer` (enforces every schema rule) → `Feed\Builder` (assembles, de-duplicates URLs, sorts, hashes) → `Service\FeedService` (caches, tracks `lastUpdated`) → `Middleware\WellKnownFeed` (HTTP).

- **`Classes/Feed/` has no TYPO3 dependency.** Only PHP and ext-intl (optional, for country validation). It is excluded from DI auto-registration in `Services.yaml` and covered by plain unit tests. Keep it that way; a future shared `letsemploy/ojobpub-php` library could be extracted from it.

- **Adapters stay sloppy, the Normalizer stays strict.** Sources map data without validating. The Normalizer strips HTML, truncates `description` to 1000 characters at a word boundary, parses amounts like `90'000`/`85k`, maps aliases (`full-time` → `permanent`, `Lehrstelle` → `apprenticeship`, `onsite` → `on-site`), checks enums and ISO codes and drops jobs whose `applyBefore` has passed. A job that is still invalid becomes an `Issue`; it never invalidates the whole feed. Put schema rules in the Normalizer, constants in `Feed\Spec`.

- **The document must stay schema-valid.** No `additionalProperties` at top level, on `employer` or on jobs. `Feed\Json` encodes `employer.location`, `locations[]`, `workLoad` and `salary` as objects (so empty ones become `{}`, never `[]`) and `jobs` as a list. `version` is the string `"1.0"`. Whole numbers are emitted as integers.

- **`lastUpdated` changes only when the content changes.** `FeedService` compares the content hash (employer + jobs, without `lastUpdated`) with the one stored in `sys_registry` (namespace `tx_ojobpub`, key `state:<site identifier>`). The ETag is derived from the same hash.

- **Caching:** cache `ojobpub` (group `pages`, so "flush frontend caches" clears it). The cache identifier includes all `ojobpub.*` site settings and the current date, so a settings change or a new day never serves a stale feed; the lifetime ends at midnight at the latest so expired jobs drop out. `Hooks\DataHandlerHook` flushes tag `ojobpub` (all sites) when a table returned by any available source's `tables()` changes.

- **Sources:** `SourceInterface` is autoconfigured with tag `ojobpub.source`; `SourceRegistry` collects them via `#[AutowireIterator]`. The site setting `ojobpub.source` selects one; unknown or unavailable ids fall back to `native`. This is public API: other job extensions are meant to ship adapters. `NativeSource` reads `tx_ojobpub_domain_model_job` (+ inline `tx_ojobpub_domain_model_location`) from the storage folders in `ojobpub.native.storagePids`. Each enabled site language yields its own entries; only `TRANSLATED_FIELDS` come from a translation, everything else (and the locations) from the default-language record. `url` is the record's link (external or `t3://page`) or, if empty, the detail page `ojobpub.native.detailPid`.

- **Middleware placement** (`Configuration/RequestMiddlewares.php`): after `typo3/cms-frontend/site`, before base redirects, EXT:redirects, static routes and the page resolver. Verified against the v14.3.9 stack only. If the site resolver did not set a `Site` (base with a path, language prefixes), the middleware falls back to the site whose base host equals the request host. GET/HEAD return the feed with `ETag`, `Last-Modified`, `Cache-Control`, `Access-Control-Allow-Origin: *`; `If-None-Match` → 304; OPTIONS → 204; other methods → 405. Disabled or unconfigured feed → falls through to TYPO3's 404.

- **Configuration** is a site set (`Configuration/Sets/OJobPub`, name `letsemploy/ojobpub`) with settings under `ojobpub.*`; labels in `labels.xlf` next to it. Only setting types that exist in both v13 and v14 are used (no `page`/`url` types).

## Decisions taken in the scaffold (revisit if wrong)

- A job without location gets the employer's headquarters; without both it is an Issue.
- Jobs with `applyBefore` in the past are dropped (spec does not require it; the WordPress plugin does it).
- Two jobs with the same `url` → the second becomes an Issue.
- Tags longer than 28 characters are dropped, not truncated.
- Cache invalidation flushes all sites.
- `ctrl.searchFields` is set only for v13 (`TCA/Overrides`); v14 removed it (Breaking #106972).
- `employer.url` defaults to the site base.
- `ojobpub.enabled` defaults to `false`: adding the set does not expose an empty or invalid feed (an invalid feed is not cached and would be rebuilt on every request).
- Only the live workspace is published (`WorkspaceRestriction(0)` in both queries).
- Records with `sys_language_uid = -1` ("All languages") are published once, as default-language entries.
- Start/end times of records take effect with up to `cacheLifetime` delay (default 1 h). Capping the lifetime at the next start/end time is possible if that matters.
- The site base must be absolute (`https://example.com/`); with a relative base, generated job URLs have no host and those jobs become Issues.
- Cache tags per site use `sha1(identifier)`, because identifiers may contain dots, which cache tags reject.

## Roadmap (in order)

1. **Dev environment** like the WordPress plugin: `compose.yaml` + Makefile targets `up`, `setup` (TYPO3 via `typo3/cms-base-distribution`, this extension as a path repository, a site with the set, two languages, storage folder), `seed` (jobs incl. edge cases: expired, missing URL, HTML in description, translations, duplicate URLs) and `feed`/`validate-all` (curl the well-known URL, validate with `check-jsonschema`). Matrix v14.3 and v13.4.
2. **Make it run** in both versions and fix what breaks: TCA migrations (Upgrade module → "Check TCA migrations" must be empty), database compare, DI (`AutowireIterator`, cache factory), middleware order on v13.4, site set settings editor, translations of job records, `t3://page` links. Known gaps: link parameters and fragments of page links are dropped; sites without an enabled default language yield no jobs.
3. **Functional tests** (`typo3/testing-framework`): middleware (200, 304, HEAD, OPTIONS, 405, disabled → 404, missing employer → 404), NativeSource with translations and hidden/start/end restrictions, cache invalidation via DataHandler, `lastUpdated` stability.
4. **Editor feedback**: show Issues and a self-test to editors (like `Self_Test.php` in the WordPress plugin: fetch the apex URL, follow ≤5 redirects like SourceTracker, compare with the generated feed). Candidates: Reports module status provider, a dashboard widget, or an info box in the job list.
5. **Detail page**: minimal list/detail plugin (or a content element) with a route enhancer for `tx_ojobpub_detail[job]`, plus schema.org `JobPosting` JSON-LD on the detail page (port `Json_Ld.php`).
6. **Parity with the WordPress Normalizer**: port `Feed\Country` (country names → ISO codes) and salary ranges in one field (`salary_range`, e.g. "90–110k"); reuse its test cases.
7. **Adapters for existing TYPO3 job extensions.** Research which are actually used with v13/v14 (Packagist downloads, TER) before writing any; one adapter per extension, `isAvailable()` via `ExtensionManagementUtility::isLoaded()`.
8. **CI and release**: GitHub Actions (lint, php-cs-fixer with `typo3/coding-standards`, PHPStan, unit + functional tests, schema validation; PHP 8.2–8.5 × TYPO3 13.4/14.3), release on tag `vX.Y.Z` to TER with `typo3/tailor` and to Packagist. Version must match in `ext_emconf.php` and the tag. Add the GPL-2.0-or-later `LICENSE` file (required by TER).
9. **Docs**: `../docs/docs/publishing/typo3.md` (install, set, settings, web server notes), linked from the publishing overview.
10. Later, v14-only: TCA type `country` for locations, setting types `page`/`url`.

## Conventions

- PHP 8.2, `declare(strict_types=1)`, final classes, constructor injection, TYPO3 CGL / PER-CS. English in code, comments and labels; German translations go in `de.*.xlf`.
- Namespace `LetsEmploy\OJobPub\`, PSR-4 from `Classes/`. Table prefix `tx_ojobpub_`, settings prefix `ojobpub.`, cache and registry namespace `ojobpub` / `tx_ojobpub`.
- New schema rules: `Feed\Spec` + `Feed\Normalizer` + a unit test, never in a source.
- Public extension points (SourceInterface, tag `ojobpub.source`, settings) are API: document changes in README.md and the docs repo.
- License GPL-2.0-or-later (TYPO3 extensions in TER must be GPL-compatible).
