#!/bin/bash
set -e

# Render sets $PORT at runtime (commonly 10000). Default to 10000 if unset
# (e.g. when testing the image locally without Render's environment).
PORT="${PORT:-10000}"

sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
