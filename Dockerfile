# Imagen de la API: PHP 8.3 con Apache
# Versión fijada en una que incluye los parches de seguridad de octubre de 2026
# (php2shell: CVE-2026-109618 y CVE-2026-109619, corregidas en PHP 8.3.36).
# Para actualizar: cambiar PHP_VERSION y reconstruir con "docker compose build --pull".
ARG PHP_VERSION=8.3.36
FROM php:${PHP_VERSION}-apache

# Extensión PDO para MySQL y módulos de Apache necesarios
RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers \
    && sed -ri 's#DocumentRoot /var/www/html#DocumentRoot /var/www/html/public#' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /var/www/html/public>\n    AllowOverride All\n</Directory>\nServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-available/sigeru.conf \
    && a2enconf sigeru \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'expose_php = Off\ndisplay_errors = Off\nlog_errors = On\n; mitigación recomendada por PHP para CVE-2026-115560 (aviso del 23/10/2026)\nsession.upload_progress.enabled = 0\n' > "$PHP_INI_DIR/conf.d/sigeru.ini" \
    && mkdir -p /var/log/sigeru \
    && chown www-data:www-data /var/log/sigeru

WORKDIR /var/www/html
COPY --chown=www-data:www-data app/ app/
COPY --chown=www-data:www-data lang/ lang/
COPY --chown=www-data:www-data public/ public/
COPY --chown=www-data:www-data bin/ bin/

HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
    CMD php -r "exit(@file_get_contents('http://localhost/salud') === false ? 1 : 0);"

EXPOSE 80
