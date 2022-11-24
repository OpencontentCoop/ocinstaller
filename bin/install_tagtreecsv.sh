#!/usr/bin/env bash

SITEACCESS=$1
FILE=$2

php vendor/opencontent/ocinstaller/bin/install_tagtreecsv.php --allow-root-user -q -s ${SITEACCESS} --file=${FILE} > /dev/null &
