#!/usr/bin/env bash
# ==============================================================================
# Model Provisioning & Integrity Verification Script
# Self-hosted, $0 runtime cost offline verification for Ka7loot PDF
# ==============================================================================
set -euo pipefail

U2NET_PATH="/opt/ka7loot-pdf/models/u2net/u2net.onnx"
U2NET_EXPECTED_SHA256="8d10d2f3bb75ae3b6d527c77944fc5e7dcd94b29809d47a739a7a728a912b491"

echo "=== Verifying Ka7loot PDF Offline AI Models ==="

# 1. Verify U-2-Net
if [ ! -f "$U2NET_PATH" ]; then
    echo "[ERROR] U-2-Net model missing at $U2NET_PATH"
    echo "Please place u2net.onnx in /opt/ka7loot-pdf/models/u2net/ with read permissions."
    exit 1
fi

echo "[INFO] Checking U-2-Net SHA-256 hash..."
U2NET_ACTUAL_SHA256=$(sha256sum "$U2NET_PATH" | awk '{print tolower($1)}')

if [ "$U2NET_ACTUAL_SHA256" != "$U2NET_EXPECTED_SHA256" ]; then
    echo "[ERROR] U-2-Net SHA-256 mismatch!"
    echo "  Expected: $U2NET_EXPECTED_SHA256"
    echo "  Actual:   $U2NET_ACTUAL_SHA256"
    exit 1
fi
echo "[OK] U-2-Net model verified ($U2NET_EXPECTED_SHA256)."

# 2. Check permissions
if [ ! -r "$U2NET_PATH" ]; then
    echo "[WARN] $U2NET_PATH is not world-readable. Running 'chmod 644 $U2NET_PATH'..."
    chmod 644 "$U2NET_PATH"
fi

echo "=== All AI models successfully verified for production deployment ==="
exit 0
