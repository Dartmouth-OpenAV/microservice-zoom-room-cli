#!/bin/bash
/initialize_db_data.sh
/usr/bin/nohup /usr/bin/php /var/www/html/api.php > /var/log/process_log &
apachectl -D FOREGROUND