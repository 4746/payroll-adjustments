# --- build stage: PHP extensions + ini ---
FROM php:8.4-fpm-alpine

# build-залежності + dev-бібліотеки для розширень
RUN apk add --no-cache \
        $PHPIZE_DEPS \
        linux-headers \
        libzip-dev \
        icu-dev \
        oniguruma-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        libwebp-dev \
        libxml2-dev \
        curl-dev

# збірка php-розширень
RUN docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" gd intl zip pdo_mysql opcache

# налаштування php.ini
COPY ./docker/php/php.ini /usr/local/etc/php/conf.d/zz-fbfast.ini

# системні залежності для runtime
# (ТУТ ставимо git, бо composer буде запускатись у цьому образі)
RUN apk add --no-cache \
        nginx \
        nginx \
        supervisor \
        openssh \
        curl \
        bash \
        nano \
        wget \
        htop \
        tar \
        zip \
        zlib-dev \
        git

# redis через pecl
RUN pecl install redis && docker-php-ext-enable redis
# xdebug через pecl
RUN pecl install xdebug && docker-php-ext-enable xdebug
# pcov через pecl
RUN pecl install pcov && docker-php-ext-enable pcov
# увімкнути потрібні розширення
RUN docker-php-ext-enable opcache

RUN mkdir -p /var/run/sshd  \
    && echo "PermitRootLogin yes" >> /etc/ssh/sshd_config \
    && echo "PasswordAuthentication yes" >> /etc/ssh/sshd_config \
    && echo "root:root" | chpasswd \
    && touch /run/supervisor.sock && chmod 777 /run/supervisor.sock \
    && mkdir -p /etc/ssh && ssh-keygen -A

WORKDIR /var/www

# composer з офіційного образу
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Встановлюємо Node.js 24 з офіційного репозиторію Alpine
RUN curl -fsSL https://unofficial-builds.nodejs.org/download/release/v24.9.0/node-v24.9.0-linux-x64-musl.tar.xz -o node.tar.xz \
    && mkdir -p /usr/local/lib/nodejs \
    && tar -xJvf node.tar.xz -C /usr/local/lib/nodejs \
    && rm node.tar.xz \
    && ln -s /usr/local/lib/nodejs/node-v24.9.0-linux-x64-musl/bin/node /usr/local/bin/node \
    && ln -s /usr/local/lib/nodejs/node-v24.9.0-linux-x64-musl/bin/npm /usr/local/bin/npm

# Додаємо шлях до npm глобальних пакетів
ENV PATH=/usr/local/lib/nodejs/node-v24.9.0-linux-x64-musl/bin:$PATH
RUN npm install pm2 -g && pm2 update

# nginx + supervisor + entrypoints
COPY ./docker/nginx.conf /etc/nginx/nginx.conf
COPY ./docker/supervisord.conf /etc/supervisord.conf
COPY ./docker/entrypoint-*.sh /
RUN chmod +x /entrypoint-*.sh &&  \
    rm -rf /tmp/pear && \
    rm -rf /var/cache/apk/* && \
    rm -rf /var/lib/apt/lists/*

ENV PHP_FPM_LISTEN=127.0.0.1:9000
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_MEMORY_LIMIT=1
# Налаштування користувача і групи
ARG PUID=1000
ARG PGID=1000

RUN apk --no-cache add shadow &&  \
    groupmod -o -g ${PGID} www-data &&  \
    usermod -o -u ${PUID} -g www-data www-data


#EXPOSE 443 9000 9003 9200

HEALTHCHECK --interval=30s --timeout=3s --retries=3 CMD curl -fsS http://localhost/health || exit 1

# за замовчуванням веб
ENTRYPOINT ["/entrypoint-web.sh"]
