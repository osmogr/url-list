FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql

RUN { \
    echo 'display_errors = Off'; \
    echo 'log_errors = On'; \
    } > /usr/local/etc/php/conf.d/error-handling.ini

RUN a2enmod ssl

RUN openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
    -keyout /etc/ssl/private/apache-selfsigned.key \
    -out /etc/ssl/certs/apache-selfsigned.crt \
    -subj "/CN=localhost" \
    -addext "subjectAltName=DNS:localhost" \
    -addext "basicConstraints=critical,CA:FALSE" \
    -addext "keyUsage=digitalSignature,keyEncipherment" \
    -addext "extendedKeyUsage=serverAuth"

RUN { \
    echo '<VirtualHost *:443>'; \
    echo '    DocumentRoot /var/www/html'; \
    echo '    SSLEngine on'; \
    echo '    SSLCertificateFile /etc/ssl/certs/apache-selfsigned.crt'; \
    echo '    SSLCertificateKeyFile /etc/ssl/private/apache-selfsigned.key'; \
    echo '    <Directory /var/www/html>'; \
    echo '        AllowOverride All'; \
    echo '    </Directory>'; \
    echo '</VirtualHost>'; \
    } > /etc/apache2/sites-available/default-ssl.conf \
    && a2ensite default-ssl

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80 443
