FROM php:8.5-apache-trixie

# .htaccess de data/ (Require all denied) honoré
RUN a2enmod rewrite && \
    sed -ri 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# data/ n'est jamais servi par le web, même si un bind-mount masque son .htaccess
RUN printf '<Directory /var/www/html/data>\n    Require all denied\n</Directory>\n' > /etc/apache2/conf-available/chicken-data.conf && \
    a2enconf chicken-data

# Site web (docroot) et serveur WebSocket (hors docroot : jamais servi par Apache)
COPY web/ /var/www/html/
COPY server/ /opt/chicken/
RUN mkdir -p /var/www/html/data && \
    [ -f /var/www/html/data/scores.json ] || echo '[]' > /var/www/html/data/scores.json; \
    chown -R www-data:www-data /var/www/html/data

# Le serveur multijoueur écrit son classement (multi_scores.json) dans le même dossier que le site
ENV CHICKEN_DATA_DIR=/var/www/html/data
VOLUME /var/www/html/data
EXPOSE 80 8081
