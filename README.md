# WP Ops Agent

A WordPress plugin that finds why a site is slow or at risk, and fixes it
safely. Open **Tools → WP Ops Agent**, click **Run scan**, and it reports
what is weighing the site down: oversized autoloaded options, piled-up cron
events, and later transients, table bloat and object-cache health. Each
finding gets a **Fix** button.

This repository is also a system design workshop. Every build adds one
distributed-systems idea to a real plugin, running on more than one server:
shared state, race conditions, locks, background queues, and safe undo. The
**System design** section below explains each idea as it is used here, and it
is updated after every build.

## What it does today

- Reports autoloaded options: count, total size, and the 20 largest.
- Reports scheduled cron events, with how many are due.
- **Fix** turns autoload off for one option and records who did it.
- **Run scan** runs the scan and saves the report. Opening the page only
  reads the saved report.
- Only one scan runs at a time, across all servers.
- Only users with the `manage_options` capability can see or use any of it.

## Where it is going

- Large scans in batches, in the background, that resume after a crash.
- A snapshot before every fix, and one-click **Undo**.
- An AI agent that answers "why is my site slow?" from the scan data and
  proposes fixes for the admin to approve, one by one.
- A WP-CLI command, WordPress Site Health checks, and tests in CI.

## System design

![Animated diagram: two admins click Run scan at once; nginx sends each click to a different WordPress server; server 1 takes the lock row in MariaDB, server 2 is told the scan is busy; server 1 saves the report and releases only its own lock.](docs/architecture.svg)

The diagram plays one real moment in the system: two admins clicking
**Run scan** at the same time. It is redrawn after every build, so it always
shows everything implemented so far.

### 1. Stateless servers behind a load balancer

**The idea.** To handle more traffic, run several copies of the app and put a
load balancer in front. That only works if a request can land on any copy and
get the same result. So the app servers keep nothing of their own: no files,
no PHP sessions, no in-memory state that matters. Everything shared lives in
the database.

**Here.** Two WordPress containers sit behind nginx, which sends each request
to the next server in turn. Both use the same database and the same
authentication salts, so a login made on one server is valid on the other.
nginx adds an `X-Upstream` header to every response, showing which server
answered. Every feature below is built to work no matter which server a
click lands on.

### 2. Lost updates: the fix history

**The idea.** Two requests read the same value, both change it, and both
write it back. The second write wipes out the first. This is a race
condition: the result depends on timing. In PHP it is easy to miss, because
one line of code can still be two database trips — a read, then a write.

**Here.** The first version kept the fix history as one array inside one
WordPress option: read the array, add an entry, save the array. Two admins
clicking **Fix** at the same moment each added their entry to their own copy,
and one entry was lost. The fix was to stop sharing one value: the history is
now its own table, `wp_wpoa_fix_history`, and every fix is a separate
`INSERT` of one row. Inserts never overwrite each other, so nothing is lost.

### 3. Compute once, read many: the saved report

**The idea.** If a result is expensive to compute and read often, compute it
once and store it. The price is that the stored copy can go out of date, so
the reader must be able to see how old it is.

**Here.** The scan used to run every time someone opened the page. Now it
runs only when someone clicks **Run scan**, and the result is saved in the
`wpoa_last_report` option. Opening the page reads that one saved report and
runs no scan queries. The page shows when the last scan ran, how long it
took and who ran it. After a fix, it says the report is from before the fix.

The report is saved with autoload **off**. Otherwise WordPress would load it
on every page of the site — adding to the very problem the plugin reports.

### 4. The scan lock

**The idea.** A lock makes sure only one worker does a job at a time, even
across servers. Three things make a lock safe:

1. **Taking it is one atomic step.** "Check if free, then take it" is two
   steps, and two workers can both pass the check.
2. **Only the owner can release it.** A worker whose lock has expired must not
   delete a lock that now belongs to someone else.
3. **A dead lock does not block forever.** If the holder crashes, the lock
   must expire.

**Here.** The lock is one row in `wp_options`, named `wpoa_scan_lock`.

- **Taking it:** `INSERT IGNORE`. The `option_name` column has a unique key,
  so the database lets exactly one insert succeed. One row inserted means
  "you have the lock"; zero means someone else has it. This is the same
  technique WordPress core uses for its own upgrade lock.
- **Who owns it:** the value is `uuid|start_time|user_id`. The UUID is new for
  every click, so each lock has a unique owner.
- **Releasing it:** `DELETE … WHERE option_name = 'wpoa_scan_lock' AND
  option_value = <my value>`. That is one statement, so the owner check and
  the delete cannot be split. If the lock now belongs to someone else, it
  deletes nothing.
- **A dead lock:** a lock older than `WPOA_SCAN_LOCK_TTL` (default 300
  seconds) is treated as abandoned. It is deleted with the same owner-checked
  `DELETE`, then the insert is tried once more. If two requests find the same
  dead lock, only one `DELETE` matches, so only one of them wins.
- **Reading it bypasses the cache:** the lock is written with raw SQL, which
  WordPress's options cache never hears about. So it is also read with a raw
  `SELECT`, never `get_option()`. With a persistent object cache, a stale
  "does not exist" entry could otherwise hide a dead lock forever.
- **Always released:** the scan runs inside `try` / `finally`, so the lock is
  freed even when the scan throws an error. A process that is killed outright
  never reaches `finally`; the TTL covers that case.

**What happens when it is busy.** The second click comes back at once with
"Another scan is already running", and the saved report is not touched.

**Why there is no fencing token.** This is an *efficiency* lock: if it ever
fails, two scans run and the report is saved twice. That wastes work but
damages nothing. A *correctness* lock — where a late writer could corrupt
data — needs more: the database must also reject writes from an old lock
holder. The snapshot and undo build will need that, not the scan.

### 5. Seeing what happened: witness lines

**The idea.** With two servers, "it works on my machine" means nothing. To
know what really happened under load, the system must leave a record that can
be counted.

**Here.** Every scan writes a line to the PHP error log when it starts, when
it ends, when a click is turned away as busy, and when it fails. Each line
names the user, the process and the server:

```
wpoa scan start user=1 pid=265 server=b7db449c34c6
wpoa scan busy user=2 pid=270 server=4f1a0c9e2d31
```

A failure also logs the error's class, message, file and line — in the log
only, never on the page, where it could reveal paths or SQL. Counting these
lines across both containers proves how many scans really ran.

## Run it locally

You need Docker with Docker Compose.

1. Create a `.env` file in this folder with the eight WordPress salts
   (`WORDPRESS_AUTH_KEY`, `WORDPRESS_SECURE_AUTH_KEY`,
   `WORDPRESS_LOGGED_IN_KEY`, `WORDPRESS_NONCE_KEY`, `WORDPRESS_AUTH_SALT`,
   `WORDPRESS_SECURE_AUTH_SALT`, `WORDPRESS_LOGGED_IN_SALT`,
   `WORDPRESS_NONCE_SALT`). Generate values at
   <https://api.wordpress.org/secret-key/1.1/salt/>. Both servers read them,
   which is what keeps logins valid on either one. `.env` is git-ignored.
2. Start the lab — nginx, two WordPress servers and MariaDB:

   ```bash
   docker compose up -d
   ```

3. Open <http://localhost:8080/wp-admin/>, finish the WordPress install, then
   activate **WP Ops Agent** under **Plugins**.
4. Open **Tools → WP Ops Agent** and click **Run scan**.

To stop the lab and keep the data:

```bash
docker compose down
```

### Testing settings

Two constants, set in `wp-config.php`, change the plugin for testing:

- `WPOA_TEST_SCAN_SECONDS` makes every scan sleep that many seconds, so
  parallel clicks really overlap. Lab only — never on a real site.
- `WPOA_SCAN_LOCK_TTL` sets how long a lock lives before it counts as
  abandoned (default 300).

### Race tests

The scripts in `tools/` fire many requests through the load balancer at once:

- `tools/race-fix-history.sh [requests] [parallel]` — many **Fix** clicks;
  counts how many reached the fix history.
- `tools/race-scan-lock.sh [requests] [parallel]` — many **Run scan** clicks;
  counts how many scans ran and how many were turned away.

They create a local test admin, so run them only against this lab.
