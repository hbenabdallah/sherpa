FROM php:8.5-cli

# mbstring, pdo_sqlite, posix, ctype and iconv ship enabled in the official
# image, and opcache is built into PHP since 8.5 — passing any of them to
# docker-php-ext-install makes the build fail. pcntl is the only one that
# genuinely needs building.
RUN apt-get update && apt-get install -y \
        git \
        unzip \
        zip \
    && docker-php-ext-install \
        pcntl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ARG USER_ID=1000
ARG GROUP_ID=1000
ARG USER=user

RUN (groupadd -g ${GROUP_ID} ${USER} 2>/dev/null || true) && \
    (useradd -u ${USER_ID} -g ${GROUP_ID} -m -s /bin/bash ${USER} 2>/dev/null || \
     usermod -u ${USER_ID} -g ${GROUP_ID} -d /home/${USER} -m -s /bin/bash ${USER} 2>/dev/null || true)

USER ${USER}

WORKDIR /app

CMD ["/bin/bash"]