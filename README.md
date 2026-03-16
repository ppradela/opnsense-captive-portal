# OPNsense 26.x Captive Portal — Production-Ready Setup Guide

> **A complete, security-hardened captive portal with user self-service password change, DNS statistics, and a modern UI. No comparable guide exists online — this is built from real production deployment.**

![OPNsense](https://img.shields.io/badge/OPNsense-26.x-orange)
![License](https://img.shields.io/badge/license-MIT-blue)
![Auth](https://img.shields.io/badge/auth-Local%20Database-green)

---

## Features

- **Modern dark UI** — responsive, TLS-encrypted, no external JS dependencies
- **OPNsense Local Database authentication** via REST API (not legacy HTML form POST)
- **DNS statistics** — top 5 visited domains per client, read directly from Unbound's DuckDB query log
- **Self-service password change** — bcrypt-hashed, policy-enforced, zero secrets exposed
- **Session timer** — live elapsed and remaining time
- **IP address display** — visible before and after login
- **Password strength meter** — visual 4-bar indicator
- **Favicon** — themed to match the portal
- **Available in English and Polish**

---

## Architecture

```
Browser (HTTPS :8000)
    │
    ├── /api/captiveportal/access/*  →  proxy  →  :8999  OPNsense auth API
    ├── /change-password             →  proxy  →  :8765  portal_backend.py
    ├── /stats?ip=...                →  proxy  →  :8765  portal_backend.py
    └── /*                          →  /var/captiveportal/zone0/htdocs/

portal_backend.py  (Python, root, outside lighttpd chroot, port 8765)
    ├── POST /change-password
    │     └── stdin JSON pipe  →  change_password.php
    │           ├── AuthenticationFactory::authenticate()  (verify old password)
    │           ├── checkPolicy()  (enforce password policy)
    │           └── local_user_set_password() + write_config()
    └── GET  /stats?ip=x.x.x.x[&since=<unix_ts>]
          └── dns_stats.py
                └── /var/unbound/data/unbound.duckdb  (reads directly, read-only)
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
│   ├── index_en.html              # Captive portal template (English)
│   ├── index_pl.html              # Captive portal template (Polish)
│   ├── portal_template_en.zip     # Ready-to-upload ZIP (English, includes favicon)
│   ├── portal_template_pl.zip     # Ready-to-upload ZIP (Polish, includes favicon)
│   └── favicon.ico                # Portal favicon (16x16, 32x32, 48x48)
├── backend/
│   ├── portal_backend.py          # HTTP backend service (port 8765)
│   ├── portal_backend.rc          # FreeBSD RC startup script
│   ├── dns_stats.py               # DNS statistics script (reads DuckDB directly)
│   ├── change_password.php        # Password change handler (PHP, runs as root)
│   └── post_reconfigure.sh        # lighttpd config hook (survives OPNsense reconfigure)
└── README.md
```

---

## Prerequisites

- OPNsense 26.x (tested on 26.1)
- Captive portal zone configured with Local Database authentication
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

# Copy from repository
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
GROUP BY domain ORDER BY count DESC LIMIT 5
```

An optional second argument accepts a Unix timestamp to filter queries made after a specific point in time (used by the portal to show stats since login).

Test:
```bash
/usr/local/opnsense/scripts/captiveportal/dns_stats.py 10.100.231.159
# → {"rows": [{"domain": "google.com", "count": 12}, ...]}
```

---

## Step 3 — Install the Password Change Script

```bash
mkdir -p /usr/local/opnsense/scripts/auth

# Copy change_password.php from this repository
cp backend/change_password.php /usr/local/opnsense/scripts/auth/change_password.php
chmod 750 /usr/local/opnsense/scripts/auth/change_password.php
chown root:wheel /usr/local/opnsense/scripts/auth/change_password.php
```

Test directly (bypasses all network layers):
```bash
echo '{"user":"testuser","old":"CurrentPass1","new":"NewPass999!"}' \
  | /usr/local/opnsense/scripts/auth/change_password.php
# → {"status":"ok"}

echo '{"user":"testuser","old":"wrongpass","new":"NewPass999!"}' \
  | /usr/local/opnsense/scripts/auth/change_password.php
# → {"status":"error","message":"Nieprawidłowe aktualne hasło."}
```

---

## Step 4 — Install and Start the Backend Service

The backend is a Python HTTP server that runs as root outside the lighttpd chroot. It handles both `/change-password` and `/stats` endpoints.

```bash
mkdir -p /usr/local/opnsense/scripts/captiveportal

# Copy from repository
cp backend/portal_backend.py \
   /usr/local/opnsense/scripts/captiveportal/portal_backend.py
cp backend/portal_backend.rc \
   /usr/local/etc/rc.d/portal_backend

chmod 750 /usr/local/opnsense/scripts/captiveportal/portal_backend.py
chmod 755 /usr/local/etc/rc.d/portal_backend
chown root:wheel /usr/local/opnsense/scripts/captiveportal/portal_backend.py

# Enable on boot
echo 'portal_backend_enable="YES"' >> /etc/rc.conf.local

# Start now
service portal_backend start
service portal_backend status
# → portal_backend is running as PID XXXXX
```

Test the backend directly:
```bash
# Stats
curl -s "http://127.0.0.1:8765/stats?ip=10.100.231.159"

# Password change (no special chars in test)
curl -s -X POST http://127.0.0.1:8765/change-password \
  -d "user=testuser&old=CurrentPass1&new=NewPass999"
# → {"status": "ok"}
```

---

## Step 5 — Patch lighttpd Config (Proxy Rules)

OPNsense regenerates the lighttpd captive portal config on every reconfigure. The post-reconfigure hook patches it automatically and performs a full lighttpd restart to ensure proxy changes take effect.

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

# Verify
grep "8765" /var/etc/lighttpd-cp-zone-0.conf
```

Expected output:
```
                "/change-password" => (
                ( "host" => "127.0.0.1",
                  "port" => 8765 )
        ),
                "/stats" => (
```

The script performs a full lighttpd restart (not just HUP) because `proxy.server` changes are not picked up by a reload signal alone. It validates the config with `lighttpd -t` before restarting.

Test through lighttpd:
```bash
curl -sk -X POST "https://<OPNsense-IP>:8000/change-password" \
  -d "user=testuser&old=CurrentPass1&new=NewPass999"
# → {"status": "ok"}
```

---

## Step 6 — Upload Portal Template

The `portal/` directory contains ready-to-upload ZIP archives:

- `portal_template_en.zip` — English UI
- `portal_template_pl.zip` — Polish UI

Each ZIP contains `index.html` (renamed from the language-specific source) and `favicon.ico`.

To build your own ZIP manually:
```bash
cd portal/
cp index_en.html index.html
zip portal_template_en.zip index.html favicon.ico
rm index.html
```

Upload to OPNsense:
1. Go to **Services → Captive Portal → Templates**
2. Upload the ZIP file
3. Assign the template to your captive portal zone

---

## Step 7 — Verify End-to-End

Open a browser in **private/incognito mode** (avoids favicon cache issues) and connect a device to the captive portal network.

You should see:
- Portal loads at `https://<OPNsense-IP>:8000`
- Your IP address shown in the info bar
- Login works with Local Database credentials
- Session timer counts up after login
- DNS statistics appear (may take a minute after first queries)
- "Change Password" button opens the password change view
- Password change succeeds and auto-returns to session view
- Sign Out ends the session

---

## Troubleshooting

### 404 on /change-password or /stats

```bash
# Check proxy rules are in lighttpd config
grep "8765" /var/etc/lighttpd-cp-zone-0.conf

# If missing — re-run the hook
sh /usr/local/opnsense/scripts/captiveportal/post_reconfigure.sh
```

### Backend not running after reboot

```bash
grep portal_backend /etc/rc.conf.local
# Should show: portal_backend_enable="YES"
service portal_backend start
```

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
/usr/local/opnsense/scripts/captiveportal/dns_stats.py <client-ip>
```

### Password change: "Invalid current password"

```bash
# Test the PHP script directly (bypasses all network/proxy layers)
echo '{"user":"USERNAME","old":"CURRENTPASS","new":"NEWPASS123"}' \
  | /usr/local/opnsense/scripts/auth/change_password.php
```

If this returns `{"status":"ok"}` but the portal doesn't, the issue is in the proxy chain. If it also returns an error, check the username exactly matches the OPNsense local database entry.

### Favicon not showing

Browser cache issue — open in a private/incognito window. The favicon is served correctly from `/var/captiveportal/zone0/htdocs/favicon.ico`.

---

## Security Notes

- **No credentials in web-accessible files** — `dns_stats.py` reads `/var/unbound/data/unbound.duckdb` directly in read-only mode; no API keys or config files are required
- **Passwords never pass through shell** — credentials sent as JSON on stdin to avoid `$` / `!` mangling
- **Password change verified before write** — `AuthenticationFactory::authenticate()` called before any config modification
- **OPNsense password policy enforced** — `checkPolicy()` respects settings in System → Access → Servers
- **lighttpd chroot respected** — backend runs outside chroot, proxied via `mod_proxy`
- **Input validation at every layer** — Python backend and PHP script both validate independently

---

## License

MIT — free to use, modify, and distribute.

---

## Author

Built through real production deployment and testing on OPNsense 26.x.
Contributions and issue reports welcome.
