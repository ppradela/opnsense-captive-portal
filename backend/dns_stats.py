#!/usr/local/bin/python3
"""
dns_stats.py

Returns top 10 domains queried by a specific client, identified by MAC address.
Falls back to IP if MAC is not available.

When given a MAC address, all IPs that have ever been leased to that MAC are
looked up from the DHCP lease database so that domain history is not lost when
a DHCP lease changes.

Supports:
  - Kea DHCP4   — /var/db/kea/kea-leases4.csv  (OPNsense 24.x+)
  - ISC dhcpd   — /var/dhcpd/var/db/dhcpd.leases  (legacy)

Usage:
  dns_stats.py <mac_or_ip> [unix_timestamp]

  mac_or_ip       — client MAC (aa:bb:cc:dd:ee:ff) or IPv4 address
  unix_timestamp  — optional; only count queries made after this time

Output: JSON to stdout
  {"rows": [{"domain": "example.com", "count": 12}, ...]}
"""

import sys
import json
import csv
import datetime
import re

import duckdb

DB_PATH      = '/var/unbound/data/unbound.duckdb'
KEA_LEASES   = '/var/db/kea/kea-leases4.csv'
DHCPD_LEASES = '/var/dhcpd/var/db/dhcpd.leases'

MAC_RE = re.compile(r'^([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}$')


def ips_for_mac(mac):
    """Return (ips, lease_start) for mac. Tries Kea then ISC dhcpd.

    lease_start is the Unix timestamp of the most recent lease start for this
    MAC — used to filter DNS queries to the current connection session.
    Returns None for lease_start if it cannot be determined.
    """
    mac_lower  = mac.lower()
    ips        = set()
    lease_start = None   # most recent lease start across all entries for mac

    # ── Kea DHCP4 (OPNsense 24.x+) ──────────────────────────────────────────
    # CSV columns: address,hwaddr,client_id,valid_lifetime,expire,subnet_id,...
    # lease_start = expire - valid_lifetime
    try:
        with open(KEA_LEASES, newline='') as f:
            for row in csv.DictReader(f):
                if row.get('hwaddr', '').lower() != mac_lower:
                    continue
                addr = row.get('address', '').strip()
                if addr:
                    ips.add(addr)
                try:
                    expire   = int(row.get('expire', 0) or 0)
                    lifetime = int(row.get('valid_lifetime', 0) or 0)
                    if expire and lifetime:
                        start = expire - lifetime
                        if lease_start is None or start > lease_start:
                            lease_start = start
                except (ValueError, TypeError):
                    pass
    except FileNotFoundError:
        pass
    except Exception:
        pass

    if ips:
        return list(ips), lease_start

    # ── ISC dhcpd (legacy) ───────────────────────────────────────────────────
    # starts field: "starts N YYYY/MM/DD HH:MM:SS;"
    current_ip    = None
    current_start = None
    try:
        with open(DHCPD_LEASES, 'r') as f:
            for line in f:
                line = line.strip()
                m = re.match(r'^lease (\S+) \{', line)
                if m:
                    current_ip    = m.group(1)
                    current_start = None
                    continue
                m = re.match(r'^starts \d+ (\d{4}/\d{2}/\d{2} \d{2}:\d{2}:\d{2});', line)
                if m and current_ip:
                    try:
                        dt = datetime.datetime.strptime(m.group(1), '%Y/%m/%d %H:%M:%S')
                        current_start = int(dt.replace(tzinfo=datetime.timezone.utc).timestamp())
                    except ValueError:
                        pass
                    continue
                m = re.match(r'^hardware ethernet (\S+);', line)
                if m and current_ip:
                    if m.group(1).lower() == mac_lower:
                        ips.add(current_ip)
                        if current_start and (lease_start is None or current_start > lease_start):
                            lease_start = current_start
    except FileNotFoundError:
        pass
    except Exception:
        pass

    return list(ips), lease_start


arg1       = sys.argv[1].strip() if len(sys.argv) > 1 else ''
since_unix = None

if len(sys.argv) > 2:
    try:
        since_unix = int(sys.argv[2])
    except ValueError:
        pass

if not arg1:
    print(json.dumps({'rows': []}))
    sys.exit(0)

if MAC_RE.match(arg1):
    client_ips, lease_start = ips_for_mac(arg1)
    if not client_ips:
        print(json.dumps({'rows': []}))
        sys.exit(0)
    # Use lease start as the since floor unless caller supplied a stricter one
    if since_unix is None and lease_start is not None:
        since_unix = lease_start
else:
    # Legacy fallback: plain IP passed directly
    client_ips = [arg1]

try:
    con = duckdb.connect(DB_PATH, read_only=True)

    placeholders = ', '.join(['?'] * len(client_ips))
    sql = f'''
        SELECT domain, COUNT(*) AS count
        FROM query
        WHERE client IN ({placeholders})
          AND domain IS NOT NULL
          AND domain NOT LIKE '%.in-addr.arpa'
          AND domain NOT LIKE '%.ip6.arpa'
    '''
    params = list(client_ips)

    if since_unix is not None:
        sql += ' AND time >= ?'
        params.append(since_unix)

    sql += ' GROUP BY domain ORDER BY count DESC LIMIT 10'

    rows = [{'domain': row[0].rstrip('.'), 'count': row[1]}
            for row in con.execute(sql, params).fetchall()]

    print(json.dumps({'rows': rows}))

except Exception as e:
    print(json.dumps({'rows': [], 'error': str(e)}))
