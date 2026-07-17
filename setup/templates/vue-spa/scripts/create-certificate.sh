#!/usr/bin/env bash

set -euo pipefail

if [ "${EUID}" -eq 0 ]; then
    echo "Do not run this script as root or with sudo."
    echo "Run it as your normal user: ./scripts/create-certificate.sh"
    exit 1
fi

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CERT_DIR="${ROOT_DIR}/certs"
CERT_FILE="${CERT_DIR}/localhost.pem"
KEY_FILE="${CERT_DIR}/localhost-key.pem"

if [ -f "${CERT_FILE}" ] && [ -f "${KEY_FILE}" ]; then
    exit 0
fi

install_mkcert() {
    if command -v mkcert >/dev/null 2>&1; then
        return
    fi

    echo "mkcert is not installed. Installing it now..."

    case "$(uname -s)" in
        Linux)
            if command -v apt >/dev/null 2>&1; then
                sudo apt update
                sudo apt install -y mkcert libnss3-tools
            elif command -v dnf >/dev/null 2>&1; then
                sudo dnf install -y mkcert nss-tools
            elif command -v yum >/dev/null 2>&1; then
                sudo yum install -y mkcert nss-tools
            elif command -v pacman >/dev/null 2>&1; then
                sudo pacman -Sy --needed mkcert nss
            else
                echo "Unsupported Linux package manager. Install mkcert manually: https://github.com/FiloSottile/mkcert"
                exit 1
            fi
            ;;
        Darwin)
            if ! command -v brew >/dev/null 2>&1; then
                echo "Homebrew is required to install mkcert automatically on macOS."
                exit 1
            fi

            brew install mkcert nss
            ;;
        *)
            echo "Unsupported OS. Install mkcert manually: https://github.com/FiloSottile/mkcert"
            exit 1
            ;;
    esac
}

install_mkcert

echo "Installing mkcert local root CA..."
mkcert -install

mkdir -p "${CERT_DIR}"

echo "Generating localhost certificates for Vite..."
mkcert \
    -cert-file "${CERT_FILE}" \
    -key-file "${KEY_FILE}" \
    localhost \
    "*.localhost"

echo "Created:"
echo "  ${CERT_FILE}"
echo "  ${KEY_FILE}"
echo "Open the SPA as https://localhost:${VITE_PORT:-5173} (not http://127.0.0.1)."
