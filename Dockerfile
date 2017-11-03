FROM php:5.6-fpm
MAINTAINER Miroslav Cillik <miro@keboola.com>

# Deps
RUN apt-get update
RUN apt-get install -y wget curl make git bzip2 time libzip-dev libssl1.0.0 openssl
RUN apt-get install -y patch unzip libsqlite3-dev gawk freetds-dev subversion
RUN apt-get install -y libpq-dev php5-dev php5-pgsql postgresql postgresql-contrib

# PHP
RUN docker-php-ext-install pdo pdo_pgsql

# CCL
WORKDIR /usr/local/src
RUN svn co http://svn.clozure.com/publicsvn/openmcl/release/1.11/linuxx86/ccl
RUN cp /usr/local/src/ccl/scripts/ccl64 /usr/local/bin/ccl

# PGloader
WORKDIR /opt/src/
RUN git clone https://github.com/dimitri/pgloader.git
WORKDIR /opt/src/pgloader
RUN git checkout tags/v3.4.1
RUN make CL=ccl DYNSIZE=1024
RUN cp /opt/src/pgloader/build/bin/pgloader /usr/local/bin

# Composer
WORKDIR /root
RUN cd \
  && curl -sS https://getcomposer.org/installer | php \
  && ln -s /root/composer.phar /usr/local/bin/composer

# Main
ADD . /code
WORKDIR /code
RUN echo "memory_limit = -1" >> /usr/local/etc/php/php.ini
RUN echo "date.timezone = \"Europe/Prague\"" >> /usr/local/etc/php/php.ini
RUN composer selfupdate && composer install --no-interaction

CMD php ./run.php --data=/data

