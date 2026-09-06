#!/usr/bin/env bash
# Root helper for Debian 12 / Ubuntu: the system libraries Recognize needs to use an NVIDIA GPU.
#
#   sudo ./install-gpu-debian.sh [/big/tmp/dir]
#
# TensorFlow 2.9 (tfjs-node-gpu 4.x) and onnxruntime-gpu 1.18 (InsightFace) need the CUDA 11
# runtime and cuDNN 8. The NVIDIA driver itself must already be installed (nvidia-smi works).
# cuDNN is a ~700 MB download that unpacks to ~3 GB, so pass a temp dir on a large filesystem
# if /tmp is small.
set -euo pipefail
[[ $(id -u) == 0 ]] || { echo "run as root" >&2; exit 1; }
command -v nvidia-smi >/dev/null || { echo "nvidia-smi not found: install the NVIDIA driver first (Debian: apt install nvidia-driver)" >&2; exit 1; }
TMP=${1:-/var/tmp/cudnn-install}
mkdir -p "$TMP"
export DEBIAN_FRONTEND=noninteractive

echo "==> CUDA 11 runtime libraries"
apt-get update -qq
apt-get install -y nvidia-cuda-toolkit libcublas11 libcublaslt11 python3 python3-venv python3-dev build-essential

if ldconfig -p | grep -q 'libcudnn.so.8'; then
	echo "==> cuDNN 8 already installed"
else
	echo "==> cuDNN 8 (non-free downloader package; accepts the NVIDIA cuDNN license)"
	echo "nvidia-cudnn nvidia-cudnn/question select I Agree" | debconf-set-selections
	apt-get install -y nvidia-cudnn || true
	if ! ldconfig -p | grep -q 'libcudnn.so.8'; then
		# the package's helper downloads/unpacks in $TMP; on small /tmp it fails, so run it explicitly
		TMPDIR=$TMP update-nvidia-cudnn -u --tmpdir "$TMP" || {
			echo "automatic cuDNN install failed; download cudnn-linux-x86_64-8.x_cuda11-archive.tar.xz from https://developer.nvidia.com/rdp/cudnn-archive and copy lib/libcudnn*.so.8* to /usr/lib/x86_64-linux-gnu, then run ldconfig" >&2
			exit 1
		}
	fi
	ldconfig
fi
echo "==> libraries visible to the dynamic linker:"
ldconfig -p | grep -E 'libcudnn.so.8|libcudart.so.11|libcublas.so.11' || true
echo
echo "If PHP-FPM runs under systemd with PrivateDevices=yes, the web server cannot see the GPU (background jobs can)."
echo "Optional: printf '[Service]\nPrivateDevices=no\n' > /etc/systemd/system/php8.x-fpm.service.d/gpu.conf && systemctl daemon-reload && systemctl restart php8.x-fpm"
echo "Then re-check in Nextcloud: occ setupchecks"
