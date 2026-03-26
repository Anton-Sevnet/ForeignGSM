#!/bin/sh
# ForeignGSM presignal SMS via MainSMS.ru HTTP API (no GoIP SIM SMS).
# One short curl; always exits 0 so Asterisk System() never blocks Dial.
#
# Config: /etc/foreigngsm-bridge.env (see foreigngsm-bridge.env.example).
# Deploy: chown root:asterisk .../foreigngsm-presignal-sms-wait.sh && chmod 750 ...
# Env file: same group, chmod 640 so asterisk can read (see foreigngsm-bridge.env.example).

ENV=${FOREIGNGSM_BRIDGE_ENV:-/etc/foreigngsm-bridge.env}
if [ -r "$ENV" ]; then
	# shellcheck source=/dev/null
	. "$ENV"
fi

MAINSMS_URL=${MAINSMS_URL:-https://mainsms.ru/api/message/send}
MAINSMS_HTTP_MAXTIME=${MAINSMS_HTTP_MAXTIME:-10}
DEST_MSISDN=${DEST_MSISDN:-}
RU_LEAD8_TO7=${RU_LEAD8_TO7:-yes}
# Same 8-char [A-Za-z0-9] token as in ForeignGSM app settings (any SMS sender).
PRESIGNAL_SMS_TOKEN=${PRESIGNAL_SMS_TOKEN:-}
# One line per attempt; directory must be writable by user asterisk.
PRESIGNAL_AUDIT_LOG=${PRESIGNAL_AUDIT_LOG:-/var/log/asterisk/foreigngsm-presignal.log}

log_err() {
	printf '%s\n' "$*" >&2
}

# Append audit line if log directory is writable (diagnostics when API=ok but handset gets nothing).
audit_append() {
	_msg="$1"
	_dir=$(dirname "$PRESIGNAL_AUDIT_LOG")
	[ -d "$_dir" ] && [ -w "$_dir" ] || return 0
	printf '%s %s\n' "$(date +%Y-%m-%d\ %H:%M:%S)" "$_msg" >>"$PRESIGNAL_AUDIT_LOG" 2>/dev/null || true
}

mainsms_digits() {
	printf '%s' "$1" | tr -cd '0123456789'
}

CID_RAW="${1:-unknown}"
CID=$(printf '%s' "$CID_RAW" | tr -d ' \t\r\n')
MSG="From:${CID} bridge"
if [ -n "$PRESIGNAL_SMS_TOKEN" ]; then
	MSG="${MSG} ${PRESIGNAL_SMS_TOKEN}"
fi
REC=$(mainsms_digits "$DEST_MSISDN")

if [ -z "$MAINSMS_PROJECT" ] || [ -z "$MAINSMS_APIKEY" ]; then
	log_err "presignal-mainsms: set MAINSMS_PROJECT and MAINSMS_APIKEY in $ENV"
	audit_append "SKIP no_mainsms_creds cid=$CID rec=${REC:-empty}"
	exit 0
fi

if [ -z "$REC" ]; then
	log_err "presignal-mainsms: DEST_MSISDN empty or has no digits"
	audit_append "SKIP no_recipient cid=$CID"
	exit 0
fi

# RU national 8XXXXXXXXXX (11 digits) -> 7XXXXXXXXXX for operator APIs
if [ "$RU_LEAD8_TO7" != "no" ] && [ "${#REC}" -eq 11 ]; then
	case $REC in
	8*) REC="7${REC#8}" ;;
	esac
fi

# Same shape as MainSMS docs: GET + multipart (-F).
out=$(curl -sS --max-time "$MAINSMS_HTTP_MAXTIME" -w "\n%{http_code}" \
	-X GET "$MAINSMS_URL" \
	-H 'Accept: application/json' \
	-F "project=$MAINSMS_PROJECT" \
	-F "recipients=$REC" \
	--form-string "message=$MSG" \
	-F "apikey=$MAINSMS_APIKEY") || {
	log_err "presignal-mainsms: curl error (dial not blocked)"
	audit_append "FAIL curl cid=$CID rec=$REC"
	exit 0
}

code=$(printf '%s\n' "$out" | tail -n1)
body=$(printf '%s\n' "$out" | sed '$d')
body1=$(printf '%s' "$body" | tr '\r\n' ' ' | cut -c1-220)
mid=$(printf '%s' "$body" | sed -n 's/.*"messages_id":\[\([0-9][0-9]*\)\].*/\1/p' | head -1)
case "$code" in
'') log_err "presignal-mainsms: empty HTTP code" ;;
2??) ;;
*) log_err "presignal-mainsms: HTTP $code body=$body" ;;
esac

audit_append "cid=$CID rec=$REC http=$code mid=${mid:-na} body=$body1"

exit 0
