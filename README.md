# OPNsense 26.x Captive Portal — Production-Ready Setup Guide

> **A complete, security-hardened captive portal with user self-service registration and password change, DNS statistics, and a modern UI. No comparable guide exists online — this is built from real production deployment.**

![OPNsense](https://img.shields.io/badge/OPNsense-26.x-orange)
![License](https://img.shields.io/badge/license-MIT-blue)
![Auth](https://img.shields.io/badge/auth-Local%20Database-green)

---

## Features

- **Modern dark UI** — animated background, large live clock (server time), two-column session layout, responsive, no external JS dependencies
- **OPNsense Local Database authentication** via REST API (not legacy HTML form POST)
- **Self-service registration** — guests and employees submit a request form; accounts are created disabled and require admin activation
- **Auto-generated usernames** from Full Name (`Adam Kowalski` → `akowalski` / `akowalski_guest`), conflict-safe with auto-increment
- **Guest accounts** — expiration date required at registration, enforced automatically by OPNsense at login
- **DNS statistics** — top 10 visited domains per client, tracked by MAC address across DHCP lease changes, read directly from Unbound's DuckDB query log, auto-refreshes every 60 s
- **Self-service password change** — bcrypt-hashed, policy-enforced, zero secrets exposed
- **Session stats** — live elapsed/remaining time, username, MAC address, ↑ Uploaded / ↓ Downloaded (auto-scaled B/KB/MB/GB)
- **IP address display** — visible before and after login
- **Password strength meter** — visual 4-bar indicator
- **Favicon** — themed to match the portal

---

## Architecture

```
Browser (HTTPS :8000)
    │
    ├── /api/captiveportal/access/*  →  proxy  →  :8999  OPNsense auth API
    ├── /change-password             →  proxy  →  :8765  portal_backend.py
    ├── /register                    →  proxy  →  :8765  portal_backend.py
    ├── /stats?ip=...                →  proxy  →  :8765  portal_backend.py
    ├── /time                        →  proxy  →  :8765  portal_backend.py
    └── /*                          →  /var/captiveportal/zone0/htdocs/

portal_backend.py  (Python, root, outside lighttpd chroot, port 8765)
    ├── POST /change-password
    │     └── stdin JSON pipe  →  change_password.php
    │           ├── AuthenticationFactory::authenticate()  (verify old password)
    │           ├── checkPolicy()  (enforce password policy)
    │           └── local_user_set_password() + write_config()
    ├── POST /register
    │     └── stdin JSON pipe  →  register.php
    │           ├── username auto-generated from Full Name + type
    │           ├── local_user_set_password() + write_config()
    │           └── adds user to 'captive' group (disabled, pending admin activation)
    ├── GET  /stats?mac=xx:xx:xx:xx:xx:xx[&ip=x.x.x.x][&since=<unix_ts>]
    │     └── dns_stats.py
    │           ├── /var/db/kea/kea-leases4.csv  (Kea DHCP4, OPNsense 24.x+)
    │           │   or /var/dhcpd/var/db/dhcpd.leases  (ISC dhcpd, legacy)
    │           └── /var/unbound/data/unbound.duckdb  (reads directly, read-only)
    └── GET  /time
          └── returns server Unix timestamp for browser clock sync
```

### Why not CGI or configd?

| Approach | Problem |
|---|---|
| HTML form POST to `$nexturllogin` | Returns 404 — OPNsense does not use this mechanism since v16 |
| `mod_cgi` in lighttpd | Not loaded in the captive portal lighttpd instance |
| configd `script_output` | Does not forward stdin — passwords with `$` get shell-mangled via argv |
| PHP proxy with API key in htdocs | API key visible to any client who downloads the file |
| **portal_backend.py + mod_proxy** | ✅ Runs outside chroot as root, stdin pipe, no secrets exposed |

---

## File Structure

```
repository/
├── portal/
│   ├── index.html                 # Captive portal template
│   ├── portal.zip                 # Ready-to-upload ZIP (includes index.html + favicon)
│   └── favicon.ico                # Portal favicon (16x16, 32x32, 48x48)
├── backend/
│   ├── portal_backend.py          # HTTP backend service (port 8765)
│   ├── portal_backend.rc          # FreeBSD RC startup script
│   ├── dns_stats.py               # DNS statistics script (reads DuckDB directly)
│   ├── change_password.php        # Password change handler (PHP, runs as root)
│   ├── register.php               # Self-service registration handler (PHP, runs as root)
│   └── post_reconfigure.sh        # lighttpd config hook (survives OPNsense reconfigure)
└── README.md
```

---

## Prerequisites

- OPNsense 26.x (tested on 26.1)
- Captive portal zone configured with Local Database authentication
- A group named **`captive`** in System → Access → Groups (registered users are added to this group)
- Unbound DNS enabled and listening on the captive portal interface
- SSH access to OPNsense

---

## Step 1 — Enable Unbound DNS Reporting

DNS statistics require Unbound to log queries to its DuckDB database.

1. Go to **Reporting → Settings**
2. Under **Unbound DNS**, enable **"Enable local gathering of DNS statistics"**
3. Save and apply

Verify it works at **Reporting → Unbound DNS → Details** — you should see live DNS queries appear in the table.

Unbound writes its query log to `/var/unbound/data/unbound.duckdb`. The `dns_stats.py` script reads this file directly with the Python `duckdb` library — no API credentials or configuration file are required.

---

## Step 2 — Install the DNS Stats Script

```bash
mkdir -p /usr/local/opnsense/scripts/captiveportal

cp backend/dns_stats.py \
   /usr/local/opnsense/scripts/captiveportal/dns_stats.py

chmod 750 /usr/local/opnsense/scripts/captiveportal/dns_stats.py
chown root:wheel /usr/local/opnsense/scripts/captiveportal/dns_stats.py
```

The script queries the `query` table in the DuckDB database:

```sql
SELECT domain, COUNT(*) AS count
FROM query
WHERE client = ?
  AND domain IS NOT NULL
  AND domain NOT LIKE '%.in-addr.arpa'
  AND domain NOT LIKE '%.ip6.arpa'
GROUP BY domain ORDER BY count DESC LIMIT 10
```

An optional second argument accepts a Unix timestamp to filter queries made after a specific point in time.

Test (MAC preferred; IP works as fallback):
```bash
/usr/local/opnsense/scripts/captiveportal/dns_stats.py aa:bb:cc:dd:ee:ff
# → {"rows": [{"domain": "google.com", "count": 12}, ...]}

# Fallback — plain IP still works
/usr/local/opnsense/scripts/captiveportal/dns_stats.py <client-ip>
```

---

## Step 3 — Install the Password Change Script

```bash
mkdir -p /usr/local/opnsense/scripts/auth

cp backend/change_password.php /usr/local/opnsense/scripts/auth/change_password.php
chmod 750 /usr/local/opnsense/scripts/auth/change_password.php
chown root:wheel /usr/local/opnsense/scripts/auth/change_password.php
```

Test directly (bypasses all network layers):
```bash
echo '{"user":"testuser","old":"CurrentPass1","new":"NewPass999!"}' \
  | /usr/local/opnsense/scripts/auth/change_password.php
# → {"status":"ok"}
```

---

## Step 4 — Install the Registration Script

```bash
cp backend/register.php \
   /usr/local/opnsense/scripts/captiveportal/register.php
chmod 750 /usr/local/opnsense/scripts/captiveportal/register.php
chown root:wheel /usr/local/opnsense/scripts/captiveportal/register.php
```

Test directly:
```bash
echo '{"type":"guest","fullname":"Jan Testowy","password":"Test1234!","reason":"Visitor","expires":"2026-12-31"}' \
  | /usr/local/opnsense/scripts/captiveportal/register.php
# → {"status":"ok","username":"jtestowy_guest"}
```

The script:
- Generates a username automatically: first letter of first name + last name + `_guest` for guests
- Appends a counter if the username is already taken (`jtestowy1_guest`, `jtestowy2_guest`, …)
- Creates the account **disabled** — admin must enable it in System → Access → Users before the user can log in
- Adds the user to the `captive` group
- Sets the expiration date (Unix timestamp) for guest accounts — OPNsense enforces this automatically at login

---

## Step 5 — Patch lighttpd Config (Proxy Rules)

OPNsense regenerates the lighttpd captive portal config on every reconfigure. The post-reconfigure hook patches it automatically and performs a full lighttpd restart to ensure proxy changes take effect.

> **This step must be done before starting the backend service** — the backend RC script calls `post_reconfigure.sh` on startup.

```bash
cp backend/post_reconfigure.sh \
   /usr/local/opnsense/scripts/captiveportal/post_reconfigure.sh
chmod 750 /usr/local/opnsense/scripts/captiveportal/post_reconfigure.sh
chown root:wheel /usr/local/opnsense/scripts/captiveportal/post_reconfigure.sh

# Register as syshook — runs after every captive portal reconfigure
mkdir -p /usr/local/etc/rc.syshook.d/captiveportal
ln -sf /usr/local/opnsense/scripts/captiveportal/post_reconfigure.sh \
       /usr/local/etc/rc.syshook.d/captiveportal/50-portal-backend

# Apply right now
sh /usr/local/opnsense/scripts/captiveportal/post_reconfigure.sh

# Verify (should return 4 lines — one per proxied route)
grep "8765" /var/etc/lighttpd-cp-zone-0.conf
```

Expected output:
```
                  "port" => 8765 )
                  "port" => 8765 )
                  "port" => 8765 )
                  "port" => 8765 )
```

The script performs a full lighttpd restart (not just HUP) because `proxy.server` changes are not picked up by a reload signal alone. It validates the config with `lighttpd -t` before restarting.

---

## Step 6 — Install and Start the Backend Service

The backend is a Python HTTP server that runs as root outside the lighttpd chroot.

```bash
cp backend/portal_backend.py \
   /usr/local/opnsense/scripts/captiveportal/portal_backend.py
cp backend/portal_backend.rc \
   /usr/local/etc/rc.d/portal_backend

chmod 750 /usr/local/opnsense/scripts/captiveportal/portal_backend.py
chmod 755 /usr/local/etc/rc.d/portal_backend
chown root:wheel /usr/local/opnsense/scripts/captiveportal/portal_backend.py

# Enable on boot
# Use /etc/rc.conf.d/ — OPNsense does not reliably read /usr/local/etc/rc.conf.d/
# and does not overwrite /etc/rc.conf.d/ on upgrades.
echo 'portal_backend_enable="YES"' > /etc/rc.conf.d/portal_backend

# Start now
service portal_backend start
service portal_backend status
# → portal_backend is running as PID XXXXX
```

Test the backend directly:
```bash
curl -s "http://127.0.0.1:8765/stats?ip=<client-ip>"
curl -s "http://127.0.0.1:8765/time"
# → {"ts": 1742791234.56}
```

---

## Step 7 — Upload Portal Template

The `portal/portal.zip` file is ready to upload. It contains `index.html` and `favicon.ico`.

Upload to OPNsense:
1. Go to **Services → Captive Portal → Templates**
2. Upload `portal/portal.zip`
3. Assign the template to your captive portal zone

> If you modify `index.html`, recreate the ZIP before uploading:
> ```bash
> cd portal/
> zip portal.zip index.html favicon.ico
> ```

---

## Step 8 — Verify End-to-End

Open a browser in **private/incognito mode** (avoids cache issues) and connect a device to the captive portal network.

You should see:
- Portal loads at `https://<OPNsense-IP>:8000`
- Clock shows server time (not browser time)
- Your IP address shown in the info bar
- Login works with Local Database credentials
- Session timer counts up after login
- DNS statistics appear (may take a minute after first queries)
- **"Create Account"** link opens the registration form
  - Employee: username generated as `firstinitiallastname`
  - Guest: username generated as `firstinitiallastname_guest`, expiration date required
  - After submit: success message shows assigned username
  - Account appears in System → Access → Users (disabled, group: captive)
- "Change Password" button opens the password change view
- Sign Out ends the session

---

## Troubleshooting

### 404 / 503 on backend routes

```bash
# Check proxy rules are in lighttpd config
grep "8765" /var/etc/lighttpd-cp-zone-0.conf
# Should return 4 lines

# If missing — re-run the hook
sh /usr/local/opnsense/scripts/captiveportal/post_reconfigure.sh

# Check backend is running
service portal_backend status

# If not running — clear stale pidfiles and restart
pkill -f portal_backend.py
rm -f /var/run/portal_backend.pid /var/run/portal_backend_watcher.pid
service portal_backend start
```

### Backend not running after reboot

```bash
# Check the enable flag is set
cat /etc/rc.conf.d/portal_backend
# Should show: portal_backend_enable="YES"

# If missing, recreate it
echo 'portal_backend_enable="YES"' > /etc/rc.conf.d/portal_backend
service portal_backend start
```

### Registration returns "Network error"

```bash
# Test backend directly
curl -s -X POST http://127.0.0.1:8765/register \
  -d 'type=employee&fullname=Test+User&reason=test&password=Test1234'
# → {"status":"ok","username":"tuser"}

# If that works but HTTPS fails, lighttpd needs restarting
kill -9 `cat /var/run/lighttpd-cp-zone-0.pid`
/usr/local/sbin/lighttpd -f /var/etc/lighttpd-cp-zone-0.conf
```

### Registered user Edit dialog is blank in OPNsense GUI

This happens when the user record is missing the UUID XML attribute required by the MVC user management API. Ensure `register.php` from this repository is deployed (it generates the UUID automatically). Users created by older versions of the script need to be deleted and re-created.

### DNS stats empty

```bash
# Is Unbound reporting enabled?
# Check: Reporting → Settings → Unbound DNS

# Is the DuckDB file present and non-empty?
ls -lh /var/unbound/data/unbound.duckdb

# Is the client using OPNsense as DNS?
# Force DNS via NAT redirect rule:
# Firewall → NAT → Port Forward
# Protocol: TCP/UDP, Dest Port: 53, Redirect to: <OPNsense-IP> port 53

# Test the script directly
/usr/local/opnsense/scripts/captiveportal/dns_stats.py aa:bb:cc:dd:ee:ff
/usr/local/opnsense/scripts/captiveportal/dns_stats.py <client-ip>  # IP fallback
```

### Password change: "Invalid current password"

```bash
# Test the PHP script directly (bypasses all network/proxy layers)
echo '{"user":"USERNAME","old":"CURRENTPASS","new":"NEWPASS123"}' \
  | /usr/local/opnsense/scripts/auth/change_password.php
```

If this returns `{"status":"ok"}` but the portal doesn't, the issue is in the proxy chain. If it also returns an error, check the username exactly matches the OPNsense local database entry.

---

## Security Notes

- **No credentials in web-accessible files** — `dns_stats.py` reads `/var/unbound/data/unbound.duckdb` directly in read-only mode; no API keys or config files are required
- **Passwords never pass through shell** — credentials sent as JSON on stdin to avoid `$` / `!` mangling
- **Password change verified before write** — `AuthenticationFactory::authenticate()` called before any config modification
- **OPNsense password policy enforced** — `checkPolicy()` respects settings in System → Access → Servers
- **Registration creates disabled accounts** — no self-activation; admin must explicitly enable each account
- **Guest expiration enforced by OPNsense** — expiration date stored as Unix timestamp in config, checked natively at every login attempt
- **lighttpd chroot respected** — backend runs outside chroot, proxied via `mod_proxy`
- **Input validation at every layer** — Python backend and PHP script both validate independently

---

## License

MIT — free to use, modify, and distribute.

---

## Author

**Przemysław Pradela**

Built through real production deployment and testing on OPNsense 26.x.

[![GitHub](https://img.shields.io/badge/GitHub-ppradela-181717?logo=github)](https://github.com/ppradela)
[![LinkedIn](https://img.shields.io/badge/LinkedIn-Przemys%C5%82aw%20Pradela-0A66C2?logo=linkedin)](https://www.linkedin.com/in/przemyslaw-pradela)
[![Website](https://img.shields.io/badge/Website-pradela.ovh-4A90D9?logo=globe)](https://pradela.ovh)

Contributions and issue reports welcome.
