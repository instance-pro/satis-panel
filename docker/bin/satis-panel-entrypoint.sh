#!/bin/sh
# Prepares the container on every start. Runs as root; php-fpm workers, satis
# and git run as www-data.
set -e

APP=/var/www/html
CONFIG_DIR=$(dirname "$SATIS_CONFIG")
log() { echo "satis-panel-entrypoint: $*"; }

mkdir -p "$CONFIG_DIR" "$SATIS_OUTPUT_DIR" "$APP/var" "$COMPOSER_HOME" "$SSH_DIR"

# ---------------------------------------------------------------- secrets
# Generated once and kept in the config volume when not provided.
gen_secret() {
    file="$CONFIG_DIR/$1"
    if [ ! -s "$file" ]; then
        head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n' > "$file"
        chmod 600 "$file"
        log "generated $2, stored in $file" >&2
    fi
    cat "$file"
}
if [ -z "$APP_SECRET" ]; then
    APP_SECRET=$(gen_secret .app_secret APP_SECRET)
    export APP_SECRET
fi
if [ -z "$WEBHOOK_SECRET" ]; then
    WEBHOOK_SECRET=$(gen_secret .webhook_secret WEBHOOK_SECRET)
    export WEBHOOK_SECRET
fi
if [ -z "$ADMIN_PASSWORD" ]; then
    log "WARNING: ADMIN_PASSWORD is empty, nobody can log in to the web UI"
fi

# ---------------------------------------------------------------- satis.json
if [ ! -f "$SATIS_CONFIG" ]; then
    log "creating initial $SATIS_CONFIG"
    cat > "$SATIS_CONFIG" <<JSON
{
    "name": "${SATIS_REPOSITORY_NAME:-satis-panel/repository}",
    "homepage": "${SATIS_HOMEPAGE:-http://localhost}",
    "output-dir": "$SATIS_OUTPUT_DIR",
    "twig-template": "$APP/satis/index.html.twig",
    "repositories": [],
    "require-all": true
}
JSON
fi

# nginx serves SATIS_OUTPUT_DIR, so satis must always build into it.
# The index.html template is ours unless satis.json says otherwise (set "twig-template"
# to another file, e.g. vendor/composer/satis/views/index.html.twig for the satis default).
php -r '
    [$_, $file, $dir, $template] = $argv;
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) { fwrite(STDERR, "satis-panel-entrypoint: WARNING: $file is not valid JSON\n"); exit(0); }
    $changed = false;
    if (($data["output-dir"] ?? null) !== $dir) { $data["output-dir"] = $dir; $changed = true; echo "satis-panel-entrypoint: set output-dir in $file to $dir\n"; }
    if (!array_key_exists("twig-template", $data)) { $data["twig-template"] = $template; $changed = true; echo "satis-panel-entrypoint: set twig-template in $file to $template\n"; }
    if ($changed) { file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"); }
' "$SATIS_CONFIG" "$SATIS_OUTPUT_DIR" "$APP/satis/index.html.twig"

# ---------------------------------------------------------------- htpasswd / nginx
touch "$SATIS_HTPASSWD_FILE"
mkdir -p /etc/nginx/snippets /etc/nginx/conf.d
case "$(echo "${SATIS_AUTH_DISABLED:-0}" | tr 'A-Z' 'a-z')" in
    1|true|yes|on)
        log "WARNING: SATIS_AUTH_DISABLED is set, package files are served without authentication"
        echo 'auth_basic off;' > /etc/nginx/snippets/satis-auth.conf
        ;;
    *)
        # Composer users (basic auth) or a logged-in admin session (auth_request) may pass.
        printf 'satisfy any;\nauth_basic "Satis";\nauth_basic_user_file %s;\nauth_request /_auth/session;\n' "$SATIS_HTPASSWD_FILE" > /etc/nginx/snippets/satis-auth.conf
        ;;
esac
envsubst '${SATIS_OUTPUT_DIR}' < /etc/nginx/templates/site.conf.template > /etc/nginx/conf.d/satis-panel.conf
rm -f /etc/nginx/sites-enabled/default

# ---------------------------------------------------------------- SSH
if [ -n "$SSH_PRIVATE_KEY" ] && [ ! -f "$SSH_DIR/id_satis_panel" ]; then
    printf '%s\n' "$SSH_PRIVATE_KEY" > "$SSH_DIR/id_satis_panel"
    chmod 600 "$SSH_DIR/id_satis_panel"
    ssh-keygen -y -f "$SSH_DIR/id_satis_panel" > "$SSH_DIR/id_satis_panel.pub" 2>/dev/null || log "WARNING: SSH_PRIVATE_KEY is not a valid private key"
    log "imported SSH key from SSH_PRIVATE_KEY"
fi
if [ -f "$SSH_DIR/id_satis_panel" ] && [ ! -f "$SSH_DIR/config" ]; then
    printf 'Host *\n    IdentityFile %s/id_satis_panel\n    IdentitiesOnly yes\n' "$SSH_DIR" > "$SSH_DIR/config"
fi
if [ -n "$SSH_KEYSCAN_HOSTS" ]; then
    for entry in $SSH_KEYSCAN_HOSTS; do
        host=${entry%%:*}
        port=${entry#*:}
        [ "$port" = "$entry" ] && port=22
        if ! grep -qs "$host" /etc/ssh/ssh_known_hosts; then
            log "scanning SSH host key of $host:$port"
            ssh-keyscan -p "$port" -H "$host" >> /etc/ssh/ssh_known_hosts 2>/dev/null \
                || log "WARNING: ssh-keyscan failed for $host:$port"
        fi
    done
fi

# ---------------------------------------------------------------- composer auth
if [ -f "$CONFIG_DIR/auth.json" ] && [ ! -e "$COMPOSER_HOME/auth.json" ]; then
    ln -s "$CONFIG_DIR/auth.json" "$COMPOSER_HOME/auth.json"
fi

# ---------------------------------------------------------------- permissions
chown -R www-data:www-data "$CONFIG_DIR" "$APP/var" "$COMPOSER_HOME" "$SSH_DIR"
chmod 700 "$SSH_DIR"
chown www-data:www-data "$SATIS_OUTPUT_DIR"
if [ -n "$(find "$SATIS_OUTPUT_DIR" -maxdepth 1 ! -user www-data -print -quit)" ]; then
    chown -R www-data:www-data "$SATIS_OUTPUT_DIR"
fi

exec "$@"
