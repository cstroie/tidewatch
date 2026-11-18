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
git clone https://github.com/cstroie/tidewatch.git /var/www/html/snmp-monitor
cd /var/www/html/snmp-monitor
php bin/passwd.php                 # creates config.php and sets the admin password
```

Edit `config.php` for the database, SNMP timeouts, retention and alert e-mail.

### Permissions

Cron writes the database as your user and the web server reads and writes it as `www-data`, so share the
`data/` directory through the group:

```sh
sudo chgrp -R www-data data config.php
chmod 2770 data && chmod 660 data/* && chmod 640 config.php
```

### Web server

Serve **only** `public/`. `data/` holds the database, including SNMP communities, and must never be reachable.
Example configs are in `contrib/` (lighttpd, nginx, Apache). For lighttpd with the project under
`/var/www/html`:

```sh
sudo cp contrib/lighttpd-tidewatch.conf /etc/lighttpd/conf-enabled/90-tidewatch.conf
sudo systemctl reload lighttpd       # then open http://<host>/tidewatch/
```

### Cron

```cron
* * * * *   php /var/www/html/snmp-monitor/bin/poll.php
*/5 * * * * php /var/www/html/snmp-monitor/bin/rollup.php
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
