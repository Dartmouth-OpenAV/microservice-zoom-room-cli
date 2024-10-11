FROM ubuntu:24.04

RUN apt-get update --fix-missing
RUN DEBIAN_FRONTEND=noninteractive apt-get install htop telnet w3m vim-tiny -y

# fixing timezone
RUN DEBIAN_FRONTEND=noninteractive apt-get install tzdata -y
ENV TZ=America/New_York
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# db
RUN DEBIAN_FRONTEND=noninteractive apt-get install mariadb-server -y
COPY _db_data.sql /db_data.sql
COPY _initialize_db_data.sh /initialize_db_data.sh
RUN chmod 750 /initialize_db_data.sh
RUN sed -i "s/password =.*/password = password/g" /etc/mysql/debian.cnf

# Web Server
RUN DEBIAN_FRONTEND=noninteractive apt-get install apache2 php php-curl php-xml libapache2-mod-php php-mysql screen -y
RUN rm /var/www/html/index.html
COPY _var_www_html /var/www/html
RUN chown -Rf www-data:www-data /var/www/html
RUN find /var/www/html -type f -exec chmod 440 {} \;
RUN find /var/www/html -type d -exec chmod 550 {} \;
RUN chmod 555 /var/www/html/*.expect.php
RUN a2enmod rewrite
COPY _etc_apache2_sites-available_default.conf /etc/apache2/sites-available/000-default.conf

# Misc stuff for Zoom Room CLI
RUN DEBIAN_FRONTEND=noninteractive apt-get install expect ssh-client php-ssh2 -y

COPY _start.sh /start.sh
RUN chmod 550 /start.sh

ENTRYPOINT /start.sh