FROM php:8.5-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.11.2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

CMD ["php", "--version"]
