#!/usr/local/bin/python3
"""
portal_backend.py

Lightweight HTTP backend for the OPNsense captive portal.
Runs as root OUTSIDE the lighttpd chroot on 127.0.0.1:8765.
lighttpd proxies /change-password and /stats to this server.

Handles two endpoints:

  POST /change-password
       Body: user=...&old=...&new=...  (URL-encoded)
       Passes credentials as JSON on stdin to change_password.php.
       No shell arguments — special characters ($, !, etc.) are safe.

  GET  /stats?ip=x.x.x.x
       Calls dns_stats.py for DNS statistics of the given client IP.

INSTALL:
  cp portal_backend.py /usr/local/opnsense/scripts/captiveportal/portal_backend.py
  chmod 750 /usr/local/opnsense/scripts/captiveportal/portal_backend.py
  chown root:wheel /usr/local/opnsense/scripts/captiveportal/portal_backend.py

START ON BOOT — add to /usr/local/etc/rc.d/portal_backend (see portal_backend.rc)
"""

import http.server
import json
import os
import re
import subprocess
import sys
from urllib.parse import parse_qs, urlparse

LISTEN_HOST = '127.0.0.1'
LISTEN_PORT = 8765

CHANGE_PASSWORD_SCRIPT = '/usr/local/opnsense/scripts/auth/change_password.php'
DNS_STATS_SCRIPT       = '/usr/local/opnsense/scripts/captiveportal/dns_stats.py'


def json_response(handler, data: dict, status: int = 200) -> None:
    body = json.dumps(data, ensure_ascii=False).encode('utf-8')
    handler.send_response(status)
    handler.send_header('Content-Type', 'application/json; charset=utf-8')
    handler.send_header('Content-Length', str(len(body)))
    handler.send_header('Cache-Control', 'no-store')
    handler.end_headers()
    handler.wfile.write(body)


class PortalHandler(http.server.BaseHTTPRequestHandler):

    # Suppress default request logging to avoid cluttering syslog with
    # every DNS stats poll. Errors still go to stderr → syslog via rc.
    def log_message(self, fmt, *args):
        pass

    def log_error(self, fmt, *args):
        sys.stderr.write(f'[portal_backend] {fmt % args}\n')

    # ── /stats?ip=x.x.x.x ───────────────────────────────────────────────────
    def handle_stats(self):
        parsed = urlparse(self.path)
        qs     = parse_qs(parsed.query)
        ip     = qs.get('ip', [''])[0]

        # Validate IPv4 address — reject anything else
        if not re.match(r'^\d{1,3}(\.\d{1,3}){3}$', ip):
            json_response(self, {'rows': []})
            return

        # Optional: Unix timestamp (ms) of session start — filter queries after login
        since_raw = qs.get('since', [''])[0]
        since_ts  = None
        if re.match(r'^\d{10,13}$', since_raw):
            # Convert ms to seconds if needed
            ts = int(since_raw)
            since_ts = ts // 1000 if ts > 9999999999 else ts

        cmd = [DNS_STATS_SCRIPT, ip]
        if since_ts:
            cmd.append(str(since_ts))

        try:
            result = subprocess.run(
                cmd,
                capture_output=True, text=True, timeout=8
            )
            data = json.loads(result.stdout.strip())
            json_response(self, data)
        except Exception as e:
            self.log_error('stats error: %s', e)
            json_response(self, {'rows': []})

    # ── POST /change-password ────────────────────────────────────────────────
    def handle_change_password(self):
        # Read POST body
        length = int(self.headers.get('Content-Length', '0') or 0)
        if length <= 0 or length > 4096:
            json_response(self, {'status': 'error', 'message': 'Invalid request.'})
            return

        raw_body = self.rfile.read(length).decode('utf-8', errors='replace')
        fields   = parse_qs(raw_body, keep_blank_values=False)

        def field(name):
            vals = fields.get(name, [])
            return vals[0] if vals else ''

        username = field('user')
        old_pass = field('old')
        new_pass = field('new')

        # Validate username — only safe characters
        if not re.match(r'^[a-zA-Z0-9_\-\.@]{1,64}$', username):
            json_response(self, {'status': 'error', 'message': 'Invalid username.'})
            return

        # Reject empty or excessively long passwords
        if not old_pass or not new_pass or len(old_pass) > 256 or len(new_pass) > 256:
            json_response(self, {'status': 'error', 'message': 'Invalid input.'})
            return

        # Build JSON payload — credentials never touch shell arguments
        # Special characters ($, !, \, ", etc.) are safe inside JSON strings
        payload = json.dumps({
            'user': username,
            'old':  old_pass,
            'new':  new_pass
        })

        # Call PHP script directly via stdin pipe — no configd, no shell
        try:
            result = subprocess.run(
                ['/usr/local/bin/php', CHANGE_PASSWORD_SCRIPT],
                input=payload,
                capture_output=True,
                text=True,
                timeout=15
            )
            output = result.stdout.strip()
            data   = json.loads(output)
            json_response(self, data)
        except subprocess.TimeoutExpired:
            json_response(self, {'status': 'error', 'message': 'Operation timed out.'})
        except (json.JSONDecodeError, Exception) as e:
            self.log_error('change_password error: %s', e)
            json_response(self, {'status': 'error', 'message': 'Internal server error.'})

    # ── Request router ───────────────────────────────────────────────────────
    def do_GET(self):
        if self.path.startswith('/stats'):
            self.handle_stats()
        else:
            json_response(self, {'error': 'not found'}, 404)

    def do_POST(self):
        if self.path == '/change-password':
            self.handle_change_password()
        else:
            json_response(self, {'error': 'not found'}, 404)


if __name__ == '__main__':
    server = http.server.HTTPServer((LISTEN_HOST, LISTEN_PORT), PortalHandler)
    sys.stderr.write(f'[portal_backend] listening on {LISTEN_HOST}:{LISTEN_PORT}\n')
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
