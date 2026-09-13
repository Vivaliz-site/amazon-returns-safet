#!/usr/bin/env bash
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
sudo apt-get update
sudo apt-get install -y curl ca-certificates
sudo apt-get remove -y nodejs npm || true
curl -fsSL https://deb.nodesource.com/setup_22.x -o /tmp/nodesource.sh
sudo bash /tmp/nodesource.sh
sudo apt-get install -y nodejs
node --version
npm --version
exec npx -y @wonderwhy-er/desktop-commander@latest remote --persist-session
