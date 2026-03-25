#!/bin/sh
# Auto-restart relay if PHP exits (per deployment plan).
CONFIG="${GOIP_RELAY_CONFIG:-/etc/goip-relay/config.json}"
RELAY_PHP="${GOIP_RELAY_SCRIPT:-/opt/goip-relay/relay.php}"
while true; do
    /usr/bin/php "$RELAY_PHP" --config="$CONFIG"
    sleep 2
done
