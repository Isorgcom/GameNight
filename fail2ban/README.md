# Banning scanners at the proxy

On 2026-11-06 a script on a rented server asked gamenight.poker for 64 guessed
paths (`/signup`, `/contact`, `/newsletter`, `/.env` ...) in two seconds, found
`register.php` from the home page, and five seconds later posted a stranger's
address into it so the site would mail that person. The application side of
that story is in CHANGELOG.md under v1.19.7, v1.19.8 and v1.19.9. This
directory is the edge side: a fail2ban jail that bans such an address before
it gets to the form.

The site runs behind Nginx Proxy Manager, and NPM writes an access log per
proxy host under `/opt/npm/data/logs/`. The filter here reads those logs and
counts answers of 403 and 404; the jail bans an address for a day once it has
drawn 20 of them inside two minutes. On the day of the scan four different
addresses each produced between 43 and 98 misses inside one minute against the
GameNight host alone. No person does that.

The ban lands in Docker's `DOCKER-USER` chain. Traffic to a port that Docker
publishes (NPM's 80 and 443) is forwarded to the container and never passes the
host's INPUT chain, which is where a stock fail2ban ban goes, so a default jail
would report bans that stopped nothing.

## Files

| File | Where it goes on the host |
|---|---|
| `filter.d/npm-scan.conf` | `/etc/fail2ban/filter.d/npm-scan.conf` |
| `jail.d/npm-scan.conf` | `/etc/fail2ban/jail.d/npm-scan.conf` |

`server-prep.sh` installs both on a fresh host. On a host that already runs
fail2ban:

```bash
install -m 0644 fail2ban/filter.d/npm-scan.conf /etc/fail2ban/filter.d/
install -m 0644 fail2ban/jail.d/npm-scan.conf   /etc/fail2ban/jail.d/
```

Then add your own addresses so you can never ban yourself. The jail's
`ignoreip` replaces the `[DEFAULT]` list, so repeat the private ranges:

```bash
cat > /etc/fail2ban/jail.d/npm-scan.local <<'EOF'
[npm-scan]
ignoreip = 127.0.0.1/8 ::1 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 100.64.0.0/10 203.0.113.7
EOF
fail2ban-client reload
```

## Checking it

```bash
fail2ban-regex /opt/npm/data/logs/proxy-host-5_access.log /etc/fail2ban/filter.d/npm-scan.conf
fail2ban-client status npm-scan
iptables -S f2b-npm-scan
tail -f /var/log/fail2ban.log
```

The first command reports how many lines of the log the filter matched; every
403 and 404 in the file should be one of them. The second lists the banned
addresses. To unban one:

```bash
fail2ban-client set npm-scan unbanip 203.0.113.7
```

## Keeping an address out for good

`npm-scan` bans on its own evidence and lets go after a day. The second jail,
`npm-blacklist`, is for what a person decides: a hosting range that keeps coming
back, the sign-up bot of 2026-11-10 that rotated six addresses in two
providers' ranges and paced each one under every limit. It reads no log; its
filter matches nothing and its `logpath` is `/dev/null`. Addresses go in by
hand, a range or a single address, and `bantime = -1` keeps them out until
someone lifts the ban. fail2ban stores the ban in its database and puts it back
after a restart.

```bash
fail2ban-client set npm-blacklist banip 203.0.113.0/24 198.51.100.7
fail2ban-client status npm-blacklist
fail2ban-client get npm-blacklist banip --with-time
fail2ban-client set npm-blacklist unbanip 203.0.113.0/24
```

The jail's `ignoreip` is the same private list as `npm-scan`'s, so a slip of the
hand cannot ban the proxy's own networks; repeat your own address in a
`jail.d/npm-blacklist.local` the same way. The list of what is banned lives on
the host, not in this repository: `fail2ban-client status npm-blacklist` is the
record.

| File | Where it goes on the host |
|---|---|
| `filter.d/npm-blacklist.conf` | `/etc/fail2ban/filter.d/npm-blacklist.conf` |
| `jail.d/npm-blacklist.conf` | `/etc/fail2ban/jail.d/npm-blacklist.conf` |

## What it does not cover

- The proxy's fallback host (requests that name no configured host, typically
  a scanner at the bare IP) writes `fallback_http_access.log` in a different
  format and is not read.
- A scanner that paces itself under 20 misses per two minutes, or one that
  asks for pages that exist.
- SSH. The host's `sshd` jail is separate, and SSH to gamenight.poker is over
  Tailscale only.
