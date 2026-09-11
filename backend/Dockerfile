# syntax=docker/dockerfile:1

FROM php:8.3-fpm-alpine

# O bind mount do compose traz os arquivos com o dono do host. Alinhar o
# www-data a esse UID/GID é o que permite ao Laravel escrever em storage/ sem
# recorrer a chmod 777. Default 1000 = primeiro usuário em Linux; no Docker
# Desktop (macOS/Windows) o valor é irrelevante, o mount já traduz o dono.
ARG UID=1000
ARG GID=1000

# pdo_mysql e bcmath compilam a partir do fonte, e a imagem alpine não traz
# toolchain — daí o pacote virtual, removido logo depois para não inchar.
RUN apk add --no-cache git unzip shadow \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath \
    && apk del .build-deps \
    && groupmod -o -g "${GID}" www-data \
    && usermod -o -u "${UID}" -g "${GID}" www-data

# Ajustes de memória e tempo para a geração de PDF — ver o arquivo.
COPY docker/php/app.ini /usr/local/etc/php/conf.d/app.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_HOME=/tmp/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/html

# Dependências em camada própria: só reinstala quando o composer.lock muda.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --prefer-dist \
    --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --optimize --no-scripts

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 9000
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
