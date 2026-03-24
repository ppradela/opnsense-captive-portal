#!/bin/sh
#
# post_reconfigure.sh
#
# Patches the captive portal lighttpd config after OPNsense regenerates it.
# lighttpd does NOT support multiple proxy.server directives — all routes
# must be in a single block. This script merges the backend routes into the
# existing proxy.server block that OPNsense generates.
#
# INSTALL:
#   cp post_reconfigure.sh \
#      /usr/local/opnsense/scripts/captiveportal/post_reconfigure.sh
#   chmod 750 /usr/local/opnsense/scripts/captiveportal/post_reconfigure.sh
#   chown root:wheel /usr/local/opnsense/scripts/captiveportal/post_reconfigure.sh
#
#   ln -sf /usr/local/opnsense/scripts/captiveportal/post_reconfigure.sh \
#           /usr/local/etc/rc.syshook.d/captiveportal/50-portal-backend

CONF="/var/etc/lighttpd-cp-zone-0.conf"
PIDFILE="/var/run/lighttpd-cp-zone-0.pid"
LOGFILE="/var/log/portal_backend.log"
LIGHTTPD="/usr/local/sbin/lighttpd"
PYTHON3="/usr/local/bin/python3"

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') post_reconfigure: $*" >> "${LOGFILE}"; }

[ -f "${CONF}" ] || { log "config not found: ${CONF}"; exit 1; }

# Already patched — check for the most recently added route.
# Checking for a specific route (not just the port) means old installs
# missing newer routes will be re-patched automatically.
grep -q '"/time"' "${CONF}" && { log "already patched, skipping"; exit 0; }

# OPNsense generates exactly one proxy.server block, ending with:
#   )
# )
# We need to insert our four routes before the closing )) of that block.
#
# Original block looks like:
#   proxy.server = ( "/api/captiveportal/access" => (
#                   ( "host" => "127.0.0.1",
#                     "port" => 8999 )
#           )
#   )
#
# Target result:
#   proxy.server = ( "/api/captiveportal/access" => (
#                   ( "host" => "127.0.0.1",
#                     "port" => 8999 )
#           ),
#                   "/change-password" => (
#                   ( "host" => "127.0.0.1",
#                     "port" => 8765 )
#           ),
#                   "/register" => (
#                   ( "host" => "127.0.0.1",
#                     "port" => 8765 )
#           ),
#                   "/stats" => (
#                   ( "host" => "127.0.0.1",
#                     "port" => 8765 )
#           ),
#                   "/time" => (
#                   ( "host" => "127.0.0.1",
#                     "port" => 8765 )
#           )
#   )

${PYTHON3} - "${CONF}" << 'PYEOF'
import sys, re

conf_path = sys.argv[1]
content   = open(conf_path).read()

# Find the closing of the proxy.server block using regex.
# OPNsense versions vary in indentation — match any whitespace before )
# then a bare ) on the next line (the proxy.server outer close).
# We want the LAST such occurrence in the file.
matches = list(re.finditer(r'([ \t]+)\)\r?\n\)', content))
if not matches:
    print(f"ERROR: could not find proxy.server closing block in {conf_path}", file=sys.stderr)
    sys.exit(1)

m      = matches[-1]   # last match = proxy.server block closing
indent = m.group(1)    # preserve original indentation

new = (
    indent + '),\n'
    '                "/change-password" => (\n'
    '                ( "host" => "127.0.0.1",\n'
    '                  "port" => 8765 )\n'
    '        ),\n'
    '                "/register" => (\n'
    '                ( "host" => "127.0.0.1",\n'
    '                  "port" => 8765 )\n'
    '        ),\n'
    '                "/stats" => (\n'
    '                ( "host" => "127.0.0.1",\n'
    '                  "port" => 8765 )\n'
    '        ),\n'
    '                "/time" => (\n'
    '                ( "host" => "127.0.0.1",\n'
    '                  "port" => 8765 )\n'
    '        )\n'
    ')'
)

patched = content[:m.start()] + new + content[m.end():]

open(conf_path, 'w').write(patched)
print("OK")
PYEOF

result=$?

if [ $result -eq 0 ]; then
    log "proxy routes merged into proxy.server block"

    # Validate config before reloading
    if ${LIGHTTPD} -t -f "${CONF}" 2>/dev/null; then
        if [ -f "${PIDFILE}" ] && kill -0 $(cat "${PIDFILE}") 2>/dev/null; then
            # Full restart — HUP does not reliably apply proxy.server changes
            kill -TERM $(cat "${PIDFILE}") 2>/dev/null
            sleep 1
            ${LIGHTTPD} -f "${CONF}"
            log "lighttpd restarted successfully"
        fi
    else
        log "ERROR: lighttpd config test failed after patch — check ${CONF}"
        ${LIGHTTPD} -t -f "${CONF}" >> "${LOGFILE}" 2>&1
    fi
else
    log "ERROR: python3 patch failed"
fi
