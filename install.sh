#!/usr/bin/env bash
# Install or update the CristianCasapu forks of Recognize and Memories from their GitHub releases.
#
#   sudo ./install.sh /var/www/nextcloud [www-data] [--no-memories] [--no-insightface] [--cpu]
#
# - downloads the latest release tarball of each app, keeps downloaded runtime files when upgrading
# - enables the apps (Recognize then installs Node.js/libtensorflow/ffmpeg itself)
# - installs the InsightFace face backend (Python virtualenv, no root needed) and switches to it
#   when no faces have been detected yet; on an existing library it only installs it and tells you
#   how to switch (the switch keeps the person names)
set -euo pipefail

NC_DIR=${1:?usage: install.sh /path/to/nextcloud [web-user] [--no-memories] [--no-insightface] [--cpu]}
shift
WEB_USER=www-data
if [[ $# -gt 0 && $1 != --* ]]; then WEB_USER=$1; shift; fi
WITH_MEMORIES=1; WITH_INSIGHTFACE=1; CPU_FLAG=""
for arg in "$@"; do
	case $arg in
		--no-memories) WITH_MEMORIES=0 ;;
		--no-insightface) WITH_INSIGHTFACE=0 ;;
		--cpu) CPU_FLAG="--cpu" ;;
		*) echo "unknown option $arg" >&2; exit 2 ;;
	esac
done
OCC="sudo -u $WEB_USER php $NC_DIR/occ"
[[ -f $NC_DIR/occ ]] || { echo "$NC_DIR/occ not found" >&2; exit 1; }
command -v curl >/dev/null || { echo "curl is required" >&2; exit 1; }

latest_asset() { # repo -> browser_download_url of the first .tar.gz asset of the latest release
	curl -fsSL -H 'Accept: application/vnd.github+json' "https://api.github.com/repos/CristianCasapu/$1/releases/latest" \
		| grep -oE '"browser_download_url": *"[^"]+\.tar\.gz"' | head -1 | cut -d'"' -f4
}

install_app() { # app preserved-dirs...
	local app=$1; shift
	local url; url=$(latest_asset "$app")
	[[ -n $url ]] || { echo "no release tarball found for $app" >&2; return 1; }
	local tmp; tmp=$(mktemp -d "${TMPDIR:-/var/tmp}/$app.XXXXXX")
	echo "==> $app: downloading $url"
	curl -fL --progress-bar "$url" -o "$tmp/app.tar.gz"
	tar -xzf "$tmp/app.tar.gz" -C "$tmp"
	[[ -f $tmp/$app/appinfo/info.xml ]] || { echo "tarball does not contain $app/appinfo/info.xml" >&2; return 1; }
	if [[ -d $NC_DIR/apps/$app ]]; then
		if [[ -d $NC_DIR/apps/$app/.git ]]; then
			echo "==> $app is a git checkout in $NC_DIR/apps/$app; not touching it (use git)"; rm -rf "$tmp"; return 0
		fi
		for dir in "$@"; do # keep runtime files downloaded at install time
			[[ -e $NC_DIR/apps/$app/$dir ]] || continue
			rm -rf "$tmp/$app/$dir"; mkdir -p "$(dirname "$tmp/$app/$dir")"
			cp -a "$NC_DIR/apps/$app/$dir" "$tmp/$app/$dir"
		done
		mv "$NC_DIR/apps/$app" "$NC_DIR/apps/$app.bak-$(date +%s)"
	fi
	mv "$tmp/$app" "$NC_DIR/apps/$app"
	chown -R "$WEB_USER:$WEB_USER" "$NC_DIR/apps/$app"
	rm -rf "$tmp"
	echo "==> $app: installed $(grep -oE '<version>[^<]+' "$NC_DIR/apps/$app/appinfo/info.xml" | cut -c10-)"
}

install_app recognize bin models node_modules/@tensorflow/tfjs-node/lib node_modules/@tensorflow/tfjs-node/deps/lib node_modules/@tensorflow/tfjs-node-gpu/lib node_modules/@tensorflow/tfjs-node-gpu/deps/lib node_modules/ffmpeg-static/ffmpeg
if [[ $WITH_MEMORIES == 1 ]]; then install_app memories bin-ext; fi

echo "==> enabling apps (Recognize downloads Node.js, libtensorflow and ffmpeg now — a few minutes)"
$OCC app:enable recognize
[[ $WITH_MEMORIES == 1 ]] && $OCC app:enable memories
$OCC upgrade >/dev/null 2>&1 || true
rm -rf "$NC_DIR"/apps/recognize.bak-* "$NC_DIR"/apps/memories.bak-* 2>/dev/null || true

if [[ $WITH_INSIGHTFACE == 1 ]]; then
	echo "==> installing the InsightFace face backend (Python virtualenv in the data directory, ~500 MB download)"
	if $OCC recognize:install-insightface $CPU_FLAG; then
		if [[ $($OCC recognize:status | grep -oE 'detected faces: [0-9]+' | grep -oE '[0-9]+') == 0 ]]; then
			$OCC recognize:switch-face-backend insightface
		else
			echo "==> faces were already detected with the built-in model; switch when convenient with:"
			echo "    $OCC recognize:switch-face-backend insightface   (person names are kept)"
		fi
	else
		echo "==> InsightFace installation failed (python3, python3-venv, python3-dev, build-essential needed); Recognize keeps using the built-in face-api model" >&2
	fi
fi

echo
echo "Done. Check Administration settings › Overview (setup checks) and › Recognize."
echo "Background jobs must run via system cron every 5 minutes: */5 * * * * $WEB_USER php -f $NC_DIR/cron.php"
$OCC recognize:status || true
