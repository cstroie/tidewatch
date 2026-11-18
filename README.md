# Tidewatch

A calm, good-looking, lightweight SNMP monitor for small labs.

Plain PHP 8.1+, SQLite (or MariaDB), cron. No Node, no build step, no framework.
Charts by [uPlot](https://github.com/leeoniya/uPlot) (vendored, 50 KB), gauges in hand-made SVG.

- **Collector**: polls every minute in parallel (pcntl fork pool). SNMP v1/v2c/v3 through the
  `php-snmp` extension, or the net-snmp CLI tools as a fallback (auto-detected).
- **Auto-discovery**: interfaces (64-bit counters), CPU, load, memory, filesystems,
  LM-SENSORS and ENTITY-SENSOR sensors, printer toner/supplies and page counters, plus custom OIDs.
- **Storage**: raw 1-min samples for 2 days, 5-min rollups for 30 days, hourly for a year.
- **UI**: overview cards with gauges and sparklines; per-device system/interface/sensor views;
  a drag-and-drop dashboard editor (graph, gauge, stat and status panels); alerts and an event log.
  Light and dark themes, responsive down to phone width.
- **Public page**: overall status plus devices and dashboards you mark public. It never shows
  IPs, descriptions or configuration.
- **Alerts**: device up/down plus warn/crit thresholds per metric (if warn > crit, lower is
  worse, e.g. toner), written to the event log and optionally e-mailed.

## Install

```sh
sudo apt install php-cli php-sqlite3 php-snmp snmp      # php-mysql for MariaDB
sudo git clone https://github.com/cstroie/tidewatch.git /var/www/tidewatch   # outside the web docroot
sudo chown -R $USER:www-data /var/www/tidewatch
cd /var/www/tidewatch
php bin/passwd.php                 # creates config.php and sets the admin password
```

Edit `config.php` for the database, SNMP timeouts, retention and alert e-mail.

### Permissions

Cron writes the database as your user and the web server reads and writes it as `www-data`, so share the
`data/` directory through the group:

```sh
sudo chgrp -R www-data data config.php
sudo chmod 2770 data && chmod 640 config.php   # sudo: setgid is dropped if you're not in www-data
```

The database file is created group-writable automatically.

The stock Debian/Ubuntu `snmpd` only exposes the system group. To monitor the host itself, comment out the
`rocommunity ... -V systemonly` lines in `/etc/snmp/snmpd.conf`, add `rocommunity public 127.0.0.1`,
and restart `snmpd`.

### Web server

Serve **only** `public/`. `data/` holds the database, including SNMP communities, and must never be reachable.
Example configs are in `contrib/` (lighttpd, nginx, Apache). For lighttpd with the project outside the docroot:

```
# /etc/lighttpd/conf-enabled/90-tidewatch.conf
url.redirect += ( "^/tidewatch$" => "/tidewatch/" )
alias.url    += ( "/tidewatch/" => "/var/www/tidewatch/public/" )
```

If the project sits inside the docroot, use `contrib/lighttpd-tidewatch.conf`, which also denies direct access.
Reload lighttpd and open `http://<host>/tidewatch/`.

### Cron

```cron
* * * * *   php /var/www/tidewatch/bin/poll.php
*/5 * * * * php /var/www/tidewatch/bin/rollup.php
```

## CLI

```sh
php bin/discover.php --add core-sw 192.168.1.1 public 2c   # add a device and discover it
php bin/discover.php 3                                      # rediscover device 3
php bin/discover.php --list
php bin/poll.php -f -v                                      # poll everything now, verbose
php bin/passwd.php                                          # change the admin password
```

## Layout

```
bin/      poll.php, rollup.php, discover.php, passwd.php
lib/      db, snmp drivers, discovery, metrics, alerts, auth, view; pages/ for admin screens
public/   index.php (public), login.php, app.php (admin), api.php (JSON), assets/
sql/      schema for SQLite and MariaDB
contrib/  web server examples
```

## License

MIT

## Live mode

Each device has a **Live** tab that reads CPU, memory, load and interface traffic straight from the device every 1–5 s and draws the last 5 minutes. Nothing is stored.

- **Stream** uses Server-Sent Events (`public/live.php`): one connection per viewer. Each stream holds a PHP worker, so at most `live_streams` (default 4) run at once and each lasts `live_stream_secs` (default 300 s; the browser reconnects).
- **Poll** sends one short request per sample (`api.php?a=live`) and has no worker cost.
- When a stream is refused or the server buffers it, the page falls back to polling on its own. To stream on lighttpd, set `server.stream-response-body = 2` for `live.php` (included in `contrib/lighttpd-tidewatch.conf`). On nginx, use `fastcgi_buffering off`.
- Live views pause after 15 minutes.

Other pages refresh every 20 s and redraw only when the poller has stored new samples.
