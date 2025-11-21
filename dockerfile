FROM php:8.2-apache

# Copia os arquivos do projeto para o container
COPY . /var/www/html/

# Habilita mod_rewrite (caso use URL amigável)
RUN a2enmod rewrite

# Dá permissão aos arquivos
RUN chown -R www-data:www-data /var/www/html

# Define a porta que será exposta
EXPOSE 80
