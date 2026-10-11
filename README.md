# WP Ops Agent

A read-only WordPress diagnostic plugin. Open **Tools → WP Ops Agent** to
inspect autoloaded options without changing them.

## Current features

- Count autoloaded options.
- Calculate their combined stored value size.
- List the 20 largest options, ordered by byte size, with their autoload status.
- Restrict the report to users with the `manage_options` capability.

The queries run when an authorised user opens the report. There is no
background scanner, automatic fix or AI assistant in this version.

## Run locally

You need Docker with Docker Compose.

From this project's root directory, run:

```bash
docker compose up -d
```

Open [the local WordPress dashboard](http://localhost:8080/wp-admin/).
On the first run, complete the WordPress installation. Then log in, open
**Plugins**, activate **WP Ops Agent**, and open **Tools → WP Ops Agent**.

To stop the containers while keeping the database and WordPress volumes:

```bash
docker compose down
```

## What the measurements mean

The scanner uses `LENGTH(option_value)` to measure the bytes stored in each
option value. It sums those bytes across all matching options, separately
from the top-20 query. Ranking uses raw bytes before display rounding.

The dashboard divides bytes by 1,024 and displays **KiB** (kibibytes), rounded
to two decimal places. This measures stored option values, not PHP memory
usage, database table size or page-response time. The queries return counts
and a small list instead of loading all option values into PHP; processing
those results and rendering the page still use PHP memory.

A large total is a reason to investigate. It does not by itself prove that
abandoned plugins caused the data or that the site is slow. WordPress 6.6
introduced a Site Health warning threshold of 800 KB; this is a warning
threshold, not a guarantee of performance above or below that value.

Reference: [WordPress Core: disabling autoload for large options](https://make.wordpress.org/core/2024/06/18/options-api-disabling-autoload-for-large-options/).

## Autoload statuses

The scanner currently includes `yes`, `on`, `auto-on` and `auto`.

- `yes`: legacy autoload-enabled status.
- `on`: explicitly enabled.
- `auto-on`: enabled by WordPress's automatic decision.
- `auto`: follows WordPress's default autoload behaviour; it is included by
  the installed version checked below.

Automatic decisions can be made when options are added or updated. The
stored `auto` status does not mean WordPress rechecks each value's size on
every page request. `no`, `off` and `auto-off` are excluded from this report.

## Verified local result — 11 October 2026

Checked with WordPress 7.1.3:

| Measurement | Result |
|---|---:|
| Autoloaded options | 119 |
| Combined stored option-value size | 42,278 bytes |
| Combined displayed size | 41.29 KiB |
| Largest options listed | 20 |

These are results from the local development database. They can change when
WordPress or plugins update options; they are not a load-test benchmark.

PHP syntax passed. The admin-page callback rendered the expected summary
and 20 rows. The same callback produced no output for an unauthenticated
user. The dashboard was also checked against a supplied screenshot.

## Requirements and limits

The plugin declares WordPress 6.0+ and PHP 7.4+. Those minimum versions have
not been tested; the checks above apply to the local installation.

Cron diagnostics, REST endpoints, WP-CLI commands, fixes, undo, distributed
locks and CI are not implemented yet.
