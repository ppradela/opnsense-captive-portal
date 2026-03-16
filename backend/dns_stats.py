#!/usr/local/bin/python3
"""
dns_stats.py

Returns top 5 domains queried by a specific client IP.
Reads directly from OPNsense's Unbound DuckDB query log.

Usage:
  dns_stats.py <client_ip>

  client_ip — IPv4 address of the captive portal client

Output: JSON to stdout
  {"rows": [{"domain": "example.com", "count": 12}, ...]}
"""

import sys
import json
import duckdb

DB_PATH = '/var/unbound/data/unbound.duckdb'

client_ip  = sys.argv[1].strip() if len(sys.argv) > 1 else ''
since_unix = None

if len(sys.argv) > 2:
    try:
        since_unix = int(sys.argv[2])
    except ValueError:
        pass

if not client_ip:
    print(json.dumps({'rows': []}))
    sys.exit(0)

try:
    con = duckdb.connect(DB_PATH, read_only=True)

    sql = '''
        SELECT domain, COUNT(*) AS count
        FROM query
        WHERE client = ?
          AND domain IS NOT NULL
          AND domain NOT LIKE '%.in-addr.arpa'
          AND domain NOT LIKE '%.ip6.arpa'
    '''
    params = [client_ip]

    if since_unix is not None:
        sql += ' AND time >= ?'
        params.append(since_unix)

    sql += ' GROUP BY domain ORDER BY count DESC LIMIT 5'

    rows = [{'domain': row[0].rstrip('.'), 'count': row[1]}
            for row in con.execute(sql, params).fetchall()]

    print(json.dumps({'rows': rows}))

except Exception as e:
    print(json.dumps({'rows': [], 'error': str(e)}))
