FROM php:8.2-apache

RUN docker-php-ext-install mysqli pdo pdo_mysql

# Palitan ang port ng Apache para sumunod sa ibinibigay ng Railway environment
RUN sed -i 's/80/8080/g' /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf

COPY . /var/www/html/
EXPOSE 8080
