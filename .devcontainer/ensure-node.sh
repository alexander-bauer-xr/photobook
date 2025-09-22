#!/usr/bin/env bash
set -euo pipefail

if command -v node >/dev/null 2>&1 && command -v npm >/dev/null 2>&1; then
  exit 0
fi

echo "[ensure-node] Installing Node.js 20"
if ! command -v curl >/dev/null 2>&1; then
  echo "[ensure-node] curl not found, installing"
  sudo apt-get update -y
  sudo apt-get install -y curl
fi

# Install Node.js 20 from NodeSource if node is missing
if ! command -v node >/dev/null 2>&1; then
  curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
  sudo apt-get install -y nodejs
fi

if command -v corepack >/dev/null 2>&1; then
  sudo corepack enable || true
fi

node --version
npm --version
