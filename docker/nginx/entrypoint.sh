#!/bin/sh

# Get certs
certbot certonly -n -d dashboard.somatecno.com.br \
  --standalone --preferred-challenges http --email hfcarrara@gmail.com --agree-tos --expand

# Kick off cron
/usr/sbin/crond -f -d 8 &

# Start nginx
/usr/sbin/nginx -g "daemon off;"