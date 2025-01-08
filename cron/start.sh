#!/bin/sh
crontab /cron/crontab
crond -f -L /dev/stdout
