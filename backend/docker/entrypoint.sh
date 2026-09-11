#!/bin/sh
# Tudo aqui existe por causa de um requisito só: `docker compose up -d` numa
# máquina limpa tem que entregar o Laravel de pé, sem nenhum passo manual.
set -e

cd /var/www/html

# O bind mount do compose cobre o /var/www/html da imagem, então o vendor/
# instalado no build fica invisível. E numa máquina limpa o host também não tem
# vendor/, porque ele é gitignored. Instalar aqui é o que dispensa ter composer
# na máquina de quem avalia.
if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] vendor/ ausente — instalando dependências"
    composer install --no-interaction --no-progress --prefer-dist
    chown -R www-data:www-data vendor
fi

# .env é gitignored pelo mesmo motivo. Sem ele o Laravel não sobe.
if [ ! -f .env ]; then
    echo "[entrypoint] .env ausente — copiando de .env.example"
    cp .env.example .env
    chown www-data:www-data .env
fi

if ! grep -qE '^APP_KEY=.+' .env; then
    echo "[entrypoint] gerando APP_KEY"
    php artisan key:generate --force
fi

mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# O healthcheck do mysql pode passar durante a fase de init, antes de o usuário
# da aplicação existir — por isso a retentativa em vez de confiar só no
# depends_on. As tabelas de sessão e cache são de migration, e sem elas
# qualquer rota web responde 500.
attempt=1
until php artisan migrate --force; do
    if [ "$attempt" -ge 10 ]; then
        echo "[entrypoint] banco não respondeu após $attempt tentativas"
        exit 1
    fi
    echo "[entrypoint] banco indisponível — nova tentativa em 3s ($attempt/10)"
    attempt=$((attempt + 1))
    sleep 3
done

exec "$@"
