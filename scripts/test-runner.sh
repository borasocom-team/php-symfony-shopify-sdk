#!/usr/bin/env bash
clear

source "/usr/local/turbolab.it/webstackup/script/base.sh"
APP_NAME=BaseCommand
EXPECTED_USER=$(logname)

fxHeader "🧪 ${APP_NAME} Test Runner"
#export XDEBUG_MODE=off

# https://github.com/TurboLabIt/webstackup/tree/master/script/php/test-runner-package.sh
source "${WEBSTACKUP_SCRIPT_DIR}php/test-runner-package.sh"

fxTitle "🧹 Cleaning up..."
#rm -rf /tmp/BaseCommandTestInstance

fxEndFooter
