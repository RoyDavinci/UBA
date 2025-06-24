#!/bin/bash

cd /var/www/html/bulksms/ubabulk

for i in {1..6}
do
    ./checkPortal.sh
    sleep 10
done

