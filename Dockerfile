# PHP 8.3 sobre Debian 13 (trixie), la misma versión que el entorno de Invision
FROM php:8.3-cli-trixie

RUN docker-php-ext-install pdo_mysql

WORKDIR /app
