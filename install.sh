#!/usr/bin/env bash
#
# Instalador de entorno local para Nifty Arbitrage Engine.
# Idempotente: puedes volver a correrlo sin romper nada.
#
#   ./install.sh                 Instalación completa + lanza workers
#   ./install.sh --workers-only  Solo (re)lanza los procesos de fondo
#   ./install.sh --no-workers    Instala pero no lanza los workers
#   ./install.sh -h | --help     Ayuda
#
set -euo pipefail

cd "$(dirname "$0")"

# --- Colores / helpers --------------------------------------------------------
if [ -t 1 ]; then
    BOLD=$'\033[1m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; RED=$'\033[31m'; DIM=$'\033[2m'; RESET=$'\033[0m'
else
    BOLD=""; GREEN=""; YELLOW=""; RED=""; DIM=""; RESET=""
fi
step() { echo "${BOLD}${GREEN}==>${RESET} ${BOLD}$*${RESET}"; }
info() { echo "    ${DIM}$*${RESET}"; }
warn() { echo "${YELLOW}!! $*${RESET}"; }
die()  { echo "${RED}xx $*${RESET}" >&2; exit 1; }

# --- Argumentos ---------------------------------------------------------------
RUN_INSTALL=true
RUN_WORKERS=true
COMPOSER_IMAGE="laravelsail/php84-composer:latest"

for arg in "$@"; do
    case "$arg" in
        --workers-only) RUN_INSTALL=false ;;
        --no-workers)   RUN_WORKERS=false ;;
        -h|--help)
            sed -n '3,9p' "$0" | sed 's/^# \{0,1\}//'
            exit 0 ;;
        *) die "Opción desconocida: $arg (usa --help)" ;;
    esac
done

SAIL="./vendor/bin/sail"

# --- 0. Requisitos ------------------------------------------------------------
require_docker() {
    command -v docker >/dev/null 2>&1 || die "Docker no está instalado o no está en el PATH."
    docker compose version >/dev/null 2>&1 || die "Se requiere Docker Compose v2 ('docker compose')."
    docker info >/dev/null 2>&1 || die "El daemon de Docker no está corriendo."
}

sail() { "$SAIL" "$@"; }

# --- 1. .env ------------------------------------------------------------------
ensure_env() {
    if [ -f .env ]; then
        info ".env ya existe, se conserva."
    else
        cp .env.example .env
        info ".env creado desde .env.example."
    fi
}

# --- 2. Composer / vendor -----------------------------------------------------
ensure_vendor() {
    if [ -x "$SAIL" ]; then
        info "Dependencias de Composer ya presentes (vendor/)."
        return
    fi
    step "Instalando dependencias de Composer (contenedor efímero $COMPOSER_IMAGE)"
    docker run --rm \
        -u "$(id -u):$(id -g)" \
        -v "$(pwd):/var/www/html" -w /var/www/html \
        "$COMPOSER_IMAGE" \
        composer install --ignore-platform-reqs --no-interaction --prefer-dist
    [ -x "$SAIL" ] || die "No se generó vendor/bin/sail tras 'composer install'."
}

# --- 3. Contenedores ----------------------------------------------------------
ensure_up() {
    step "Levantando contenedores (Sail: laravel.test, mysql, redis)"
    sail up -d
}

# --- 4. APP_KEY ---------------------------------------------------------------
ensure_app_key() {
    if grep -qE '^APP_KEY=.+' .env; then
        info "APP_KEY ya configurada."
    else
        step "Generando APP_KEY"
        sail artisan key:generate
    fi
}

# --- 5. Migraciones (esperando a MySQL) --------------------------------------
ensure_migrations() {
    step "Esperando a que MySQL acepte conexiones"
    local tries=0
    until sail exec -T mysql mysqladmin ping --silent >/dev/null 2>&1; do
        tries=$((tries + 1))
        [ "$tries" -ge 60 ] && die "MySQL no respondió tras 60 intentos."
        sleep 2
    done
    info "MySQL listo."
    step "Ejecutando migraciones"
    sail artisan migrate --force
}

# --- 6. Frontend --------------------------------------------------------------
build_frontend() {
    step "Instalando dependencias de Node"
    sail npm install
    step "Compilando frontend (vite build)"
    sail npm run build
}

# --- 7. Workers ---------------------------------------------------------------
# Relanza un worker artisan de larga vida en background dentro del contenedor.
# Usa el truco [a]rtisan para que pkill no haga match con su propio comando.
restart_worker() {
    local match="$1"; shift
    local cmd="$1"; shift
    local logfile="$1"; shift
    sail exec -T laravel.test sh -lc "pkill -f '[${match:0:1}]${match:1}'" >/dev/null 2>&1 || true
    sleep 1
    sail exec -T -d laravel.test sh -lc "php artisan $cmd >> storage/logs/$logfile 2>&1"
}

launch_workers() {
    step "Lanzando procesos de fondo (Reverb, market:feed, arbitrage:run)"
    sail artisan config:clear >/dev/null 2>&1 || true

    restart_worker "artisan reverb:start"  "reverb:start"  "reverb.out"
    info "reverb:start lanzado."

    restart_worker "artisan market:feed"   "market:feed --exchanges=binance,kraken,coinbase,bybit,okx,bitget"  "feed.out"
    info "market:feed lanzado."

    restart_worker "artisan arbitrage:run" "arbitrage:run" "engine.out"
    info "arbitrage:run lanzado."

    sleep 2
    info "Procesos artisan activos en el contenedor:"
    sail exec -T laravel.test sh -lc "ps aux | grep '[a]rtisan' | awk '{\$2=\$2; print \"      \"\$0}' | cut -c1-160" || true
}

# --- Resumen ------------------------------------------------------------------
summary() {
    local port; port="$(grep -E '^APP_PORT=' .env | cut -d= -f2)"; port="${port:-18080}"
    echo
    step "Listo ✅"
    echo "    Consola (SPA):  ${BOLD}http://localhost:${port}/console${RESET}"
    echo "    Health API:     ${BOLD}http://localhost:${port}/api/v1/health${RESET}"
    echo
    info "Frontend con hot-reload (opcional): ./vendor/bin/sail npm run dev"
    info "Relanzar solo workers:              ./install.sh --workers-only"
    info "Apagar todo:                        ./vendor/bin/sail down"
}

# --- Main ---------------------------------------------------------------------
require_docker

if [ "$RUN_INSTALL" = true ]; then
    ensure_env
    ensure_vendor
    ensure_up
    ensure_app_key
    ensure_migrations
    build_frontend
else
    [ -x "$SAIL" ] || die "vendor/bin/sail no existe; corre './install.sh' sin --workers-only primero."
    ensure_up
fi

if [ "$RUN_WORKERS" = true ]; then
    launch_workers
fi

summary
