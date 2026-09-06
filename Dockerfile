FROM php:8.4-cli

# mbstring, pdo_sqlite, posix, ctype and iconv ship enabled in the official
# image — passing them to docker-php-ext-install makes the build fail.
# opcache and pcntl are the only two that genuinely need building.
RUN apt-get update && apt-get install -y \
        git \
        unzip \
        zip \
    && docker-php-ext-install \
        opcache \
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