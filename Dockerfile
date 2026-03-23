FROM ubuntu:noble-20250127
LABEL maintainer="sameer@damagehead.com"

ENV PHP_VERSION=8.3 \
    INVOICEPLANE_VERSION=1.7.1 \
    INVOICEPLANE_USER=www-data \
    INVOICEPLANE_INSTALL_DIR=/var/www/invoiceplane \
    INVOICEPLANE_DATA_DIR=/var/lib/invoiceplane \
    INVOICEPLANE_CACHE_DIR=/etc/docker-invoiceplane

ENV INVOICEPLANE_BUILD_DIR=${INVOICEPLANE_CACHE_DIR}/build \
    INVOICEPLANE_RUNTIME_DIR=${INVOICEPLANE_CACHE_DIR}/runtime

RUN apt-get update \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y wget sudo unzip \
      php${PHP_VERSION}-fpm php${PHP_VERSION}-cli php${PHP_VERSION}-mysql \
      php${PHP_VERSION}-gd php${PHP_VERSION}-mbstring \
      php${PHP_VERSION}-bcmath php${PHP_VERSION}-xml php${PHP_VERSION}-intl \
      php${PHP_VERSION}-curl \
      default-mysql-client nginx gettext-base \
 && sed -i 's/^listen = .*/listen = 0.0.0.0:9000/' /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf \
 && rm -rf /var/lib/apt/lists/*

COPY assets/build/ ${INVOICEPLANE_BUILD_DIR}/

RUN bash ${INVOICEPLANE_BUILD_DIR}/install.sh

COPY assets/runtime/ ${INVOICEPLANE_RUNTIME_DIR}/

COPY assets/tools/ /usr/bin/

COPY entrypoint.sh /sbin/entrypoint.sh

RUN chmod 755 /sbin/entrypoint.sh

WORKDIR ${INVOICEPLANE_INSTALL_DIR}

ENTRYPOINT ["/sbin/entrypoint.sh"]

CMD ["app:invoiceplane"]

EXPOSE 80/tcp 9000/tcp
