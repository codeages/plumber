FROM php:7.0-cli

RUN echo "deb http://mirrors.aliyun.com/debian/ jessie main non-free contrib \n \
          deb http://mirrors.aliyun.com/debian/ jessie-proposed-updates main non-free contrib \n \
          deb-src http://mirrors.aliyun.com/debian/ jessie main non-free contrib \n \
          deb-src http://mirrors.aliyun.com/debian/ jessie-proposed-updates main non-free contrib" \
        | tee /etc/apt/sources.list \
    && apt-get update

RUN BUILD_DEPS='libpcre3-dev libcurl4-gnutls-dev zlib1g-dev' \
    && apt-get install -y curl zip unzip $BUILD_DEPS  \
    && docker-php-source extract \
    && docker-php-ext-install -j$(nproc) curl zip \
    && pecl install swoole \
    && docker-php-ext-enable swoole \
    && docker-php-source delete \
    && curl -O http://ojc8jepus.bkt.clouddn.com/composer-1.3.1.phar \
    && mv composer-1.3.1.phar /usr/local/bin/composer \
    && chmod +x /usr/local/bin/composer \
    && composer config -g repo.packagist composer https://packagist.phpcomposer.com \
    && apt-get purge -y --auto-remove $buildDeps \
    && apt-get -y autoremove \
    && apt-get clean

WORKDIR /code
COPY . /code

RUN composer install

ENTRYPOINT ["bin/plumber", "run"]
CMD ["-b", "example/bootstrap.php"]
