FROM php:8.4-fpm

ARG USER_ID=1000
ARG GROUP_ID=1000
ARG XDEBUG_VERSION=3.4.0

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y \
    git \
    zip \
    unzip \
    curl \
    vim \
    build-essential \
    python3 \
    inotify-tools \
    libicu-dev \
    libsodium-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    && curl -L -o xdebug.tgz "https://xdebug.org/files/xdebug-${XDEBUG_VERSION}.tgz" \
    && tar -xvzf xdebug.tgz \
    && cd xdebug-${XDEBUG_VERSION} \
    && phpize \
    && ./configure --with-pic \
    && make \
    && make install \
    && cd .. \
    && rm -r xdebug-${XDEBUG_VERSION} xdebug.tgz \
    && docker-php-ext-enable xdebug \
    && docker-php-ext-configure intl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql intl pcntl sockets sodium zip exif gd \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

RUN curl -sL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

RUN npm install -g npm@latest

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN groupadd -g ${GROUP_ID} appgroup && \
    useradd -u ${USER_ID} -g appgroup -m -s /bin/bash appuser

RUN chown -R appuser:appgroup /var/www/html

COPY ./docker/php/config/local.ini /usr/local/etc/php/conf.d/

USER appuser

RUN git config --global --add safe.directory /var/www/html

ENV VITE_SERVER_HOST=0.0.0.0
ENV VITE_SERVER_PORT=5173
ENV VITE_HMR_HOST=localhost

ENV PATH /var/www/html/node_modules/.bin:$PATH