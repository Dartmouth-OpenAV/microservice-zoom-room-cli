#!/bin/bash
# this isn't a big secret, only local interface is bound
MYSQLPASS="password"
service mariadb start
# making sure that the db is up
mysql -u root -e "SELECT 1" > /dev/null
while [ $? -ne 0 ]
do
    echo "DB hasn't come up yet"
    sleep 1
    service mariadb start
    mysql -u root -e "SELECT 1" > /dev/null
done
service mariadb status
while [ $? -ne 0 ]
do
    echo "DB isn't really up yet"
    sleep 1
    service mariadb status
done
# at this point I've tried everything else, the sleep just needs to be here
sleep 10
mysql -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD('"$MYSQLPASS"');GRANT ALL PRIVILEGES ON *.* TO 'root'@'%' identified by '"$MYSQLPASS"';GRANT ALL PRIVILEGES ON *.* TO 'root'@'localhost' identified by '"$MYSQLPASS"';FLUSH PRIVILEGES;"
chmod 444 /db_data.sql
mysql -u root -p$MYSQLPASS < /db_data.sql
chmod 000 /db_data.sql