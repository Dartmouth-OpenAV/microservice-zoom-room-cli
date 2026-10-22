FROM ubuntu:24.04

RUN apt-get update --fix-missing
RUN DEBIAN_FRONTEND=noninteractive apt-get install htop telnet w3m vim-tiny -y

# Install prerequisites
RUN apt-get update && apt-get install -y \
    ca-certificates \
    curl \
    lsb-release \
    && rm -rf /var/lib/apt/lists/*

# Add the PHP repository and its signing key
RUN curl -fsSL \
    https://packages.sury.org/debsuryorg-archive-keyring.deb \
    -o /tmp/debsuryorg-archive-keyring.deb \
    && dpkg -i /tmp/debsuryorg-archive-keyring.deb \
    && rm /tmp/debsuryorg-archive-keyring.deb \
    && printf 'Types: deb\nURIs: https://packages.sury.org/php/\nSuites: trixie\nComponents: main\nSigned-By: /usr/share/keyrings/debsuryorg-archive-keyring.gpg\n' \
       > /etc/apt/sources.list.d/php.sources

# fixing timezone
#RUN DEBIAN_FRONTEND=noninteractive apt-get install tzdata -y
RUN apt-get update && \
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        tzdata \
    && rm -rf /var/lib/apt/lists/*
ENV TZ=America/New_York
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# db
#RUN DEBIAN_FRONTEND=noninteractive apt-get install mariadb-server -y
RUN apt-get update && \
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        mariadb-server \
    && rm -rf /var/lib/apt/lists/*
COPY _db_data.sql /db_data.sql
COPY _initialize_db_data.sh /initialize_db_data.sh
RUN chmod 750 /initialize_db_data.sh
#RUN sed -i "s/password =.*/password = password/g" /etc/mysql/debian.cnf
RUN bash -c 'echo -e "[mysqld]\nmax_heap_table_size=512M" > /etc/mysql/mariadb.conf.d/50-server_heap.cnf'

# Web Server
#RUN DEBIAN_FRONTEND=noninteractive apt-get install apache2 php php-curl php-xml libapache2-mod-php php-mysql screen -y
RUN apt-get update && \
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        apache2 \
        php8.5 \
        php8.5-curl \
        php8.5-xml \
        libapache2-mod-php8.5 \
        php8.5-mysql \
        screen \
    && rm -rf /var/lib/apt/lists/*
RUN rm /var/www/html/index.html
COPY _var_www_html /var/www/html
RUN chown -Rf www-data:www-data /var/www/html
RUN find /var/www/html -type f -exec chmod 440 {} \;
RUN find /var/www/html -type d -exec chmod 550 {} \;
RUN chmod 555 /var/www/html/*.expect.php
RUN a2enmod rewrite
COPY _etc_apache2_sites-available_default.conf /etc/apache2/sites-available/000-default.conf

# Misc stuff for Zoom Room CLI
#RUN DEBIAN_FRONTEND=noninteractive apt-get install expect ssh-client php-ssh2 -y
RUN apt-get update && \
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        expect \
        openssh-client \
        php8.5-ssh2 \
    && rm -rf /var/lib/apt/lists/*

COPY _start.sh /start.sh
RUN chmod 550 /start.sh

ENTRYPOINT /start.sh
