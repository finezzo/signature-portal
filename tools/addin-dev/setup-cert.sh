#!/bin/bash
#
# One-time setup on macOS: creates a certificate for https://localhost:8443 and
# trusts it for that hostname. Outlook only loads add-ins over HTTPS with a
# certificate it trusts.
#
# Deliberately a self-signed leaf (CA:FALSE), not a local certificate authority:
# its key cannot sign certificates for other sites, so a leak can't be used to
# impersonate anything but localhost. The key stays in your home directory,
# outside this OneDrive-synced project.
#
# macOS asks for your login password to record the trust setting.
# Undo: security delete-certificate -c "SignaturePortal add-in dev" and delete
# ~/.signatureportal-addin-dev.

set -euo pipefail

DIR="$HOME/.signatureportal-addin-dev"
mkdir -p "$DIR"
chmod 700 "$DIR"

if [[ ! -f "$DIR/cert.pem" ]]; then
    openssl req -x509 -newkey rsa:2048 -nodes -days 825 \
        -keyout "$DIR/key.pem" -out "$DIR/cert.pem" \
        -subj "/CN=SignaturePortal add-in dev" \
        -addext "subjectAltName=DNS:localhost,IP:127.0.0.1" \
        -addext "basicConstraints=critical,CA:FALSE" \
        -addext "keyUsage=critical,digitalSignature,keyEncipherment" \
        -addext "extendedKeyUsage=serverAuth" 2>/dev/null
    chmod 600 "$DIR/key.pem"
    echo "Created $DIR/cert.pem"
fi

security add-trusted-cert -r trustRoot -p ssl -s localhost \
    -k "$HOME/Library/Keychains/login.keychain-db" "$DIR/cert.pem"

echo "Trusted for localhost. Restart Outlook and your browser once so they pick it up."
