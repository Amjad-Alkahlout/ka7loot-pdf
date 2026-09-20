#!/usr/bin/env bash
# ==============================================================================
# Model Provisioning & Integrity Verification Script
# Self-hosted, $0 runtime cost offline provisioning for Ka7loot PDF
# ==============================================================================
set -euo pipefail

U2NET_DIR="/opt/ka7loot-pdf/models/u2net"
U2NET_PATH="${U2NET_DIR}/u2net.onnx"
U2NET_EXPECTED_SHA256="8d10d2f3bb75ae3b6d527c77944fc5e7dcd94b29809d47a739a7a728a912b491"

echo "=== Ka7loot PDF Offline AI Model Provisioning ==="

if [ $# -eq 1 ]; then
    SOURCE_MODEL="$1"
    if [ ! -f "$SOURCE_MODEL" ]; then
        echo "[ERROR] Source model file not found: $SOURCE_MODEL"
        exit 1
    fi
    echo "[INFO] Provisioning U-2-Net model from local path: $SOURCE_MODEL"

    # Needs root/sudo to write to /opt
    if [ ! -w "$(dirname "$U2NET_DIR")" ] && [ "$EUID" -ne 0 ]; then
        echo "[WARN] You may need sudo to create directories in /opt/"
    fi

    mkdir -p "$U2NET_DIR"
    cp "$SOURCE_MODEL" "$U2NET_PATH"
    chmod 644 "$U2NET_PATH"
    echo "[INFO] Model copied to $U2NET_PATH"
fi

# 1. Verify U-2-Net
if [ ! -f "$U2NET_PATH" ]; then
    echo "[ERROR] U-2-Net model missing at $U2NET_PATH"
    echo "Usage to provision: sudo $0 </path/to/downloaded/u2net.onnx>"
    exit 1
fi

echo "[INFO] Checking U-2-Net SHA-256 hash..."
U2NET_ACTUAL_SHA256=$(sha256sum "$U2NET_PATH" | awk '{print tolower($1)}')

if [ "$U2NET_ACTUAL_SHA256" != "$U2NET_EXPECTED_SHA256" ]; then
    echo "[ERROR] U-2-Net SHA-256 mismatch!"
    echo "  Expected: $U2NET_EXPECTED_SHA256"
    echo "  Actual:   $U2NET_ACTUAL_SHA256"

    if [ $# -eq 1 ]; then
        echo "[INFO] Removing invalid model file to prevent corruption..."
        rm -f "$U2NET_PATH"
    fi
    exit 1
fi
echo "[OK] U-2-Net model verified ($U2NET_EXPECTED_SHA256)."

# 2. Check permissions
if [ ! -r "$U2NET_PATH" ]; then
    echo "[WARN] $U2NET_PATH is not world-readable. Running 'chmod 644 $U2NET_PATH'..."
    chmod 644 "$U2NET_PATH"
fi

echo "=== All AI models successfully provisioned and verified for production deployment ==="
exit 0
