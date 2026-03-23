#!/bin/bash

# load environment variables from .env if present
if [ -f /var/www/html/.env ]; then
    export $(grep -v '^#' /var/www/html/.env | xargs)
fi

# Wait for mysql to be ready
until mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -e "select 1" &> /dev/null; do
  echo "Waiting for MySQL..."
  sleep 2
done

# Import schema, seed data, and follow-up migrations used by the current app
mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" < /var/www/html/database/schema.sql
mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" < /var/www/html/database/seed.sql

if [ -f /var/www/html/database/add_likes_replies.sql ]; then
  mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" < /var/www/html/database/add_likes_replies.sql
fi

if [ -f /var/www/html/database/add_user_profile_fields.sql ]; then
  mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" < /var/www/html/database/add_user_profile_fields.sql
fi

echo "Database initialized"
