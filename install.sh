#!/bin/bash

set -eu

# Enable debugging output
#set -x

# Script metadata
readonly SCRIPT_VERSION="0.0.1"
readonly SCRIPT_NAME="imh-backup-disk-usage"
readonly BASE_URL="https://raw.githubusercontent.com/gemini2463/$SCRIPT_NAME/master"

# Color codes for output
readonly RED='\033[0;31m'
readonly GREEN='\033[0;32m'
readonly BLUE='\033[0;34m'
readonly BRIGHTBLUE='\033[1;34m'
readonly YELLOW='\033[1;33m'
readonly NC='\033[0m' # No Color

# Function to check if running as root
check_root() {
    if [[ $EUID -ne 0 ]]; then
        error_exit "This script must be run as root"
    fi
}

# Function to print colored output
print_message() {
    local color=$1
    local message=$2
    echo -e "${color}${message}${NC}"
}

# Function to handle errors
error_exit() {
    print_message "$RED" "ERROR: $1" >&2
    cleanup
    exit 1
}

cleanup() {
    if [[ -n "${TEMP_DIR:-}" && -d "$TEMP_DIR" ]]; then
        rm -rf "$TEMP_DIR"
    fi
}

trap cleanup EXIT INT TERM

# Function to detect control panel
detect_control_panel() {
    if [[ (-d /usr/local/cpanel || -d /var/cpanel || -d /etc/cpanel) &&
        (-f /usr/local/cpanel/cpanel || -f /usr/local/cpanel/version) ]]; then
        echo "cpanel"
    elif [[ -d /usr/local/cwpsrv ]]; then
        echo "cwp"
    else
        echo "none"
    fi
}

if [[ "${1:-}" == "--uninstall" ]]; then
    uninstall_main() {
        echo -e "\033[0;31mUninstalling $SCRIPT_NAME...\033[0m"
        local panel=$(detect_control_panel)

        case "$panel" in
        "cpanel")
            rm -rf "/usr/local/cpanel/whostmgr/docroot/cgi/$SCRIPT_NAME"
            rm -f "/usr/local/cpanel/whostmgr/docroot/addon_plugins/$SCRIPT_NAME.png"
            /usr/local/cpanel/bin/unregister_appconfig "$SCRIPT_NAME" || true
            ;;
        "cwp")
            rm -f "/usr/local/cwpsrv/htdocs/resources/admin/modules/$SCRIPT_NAME.php"
            rm -f "/usr/local/cwpsrv/htdocs/admin/design/img/$SCRIPT_NAME.png"
            rm -f "/usr/local/cwpsrv/htdocs/admin/design/js/$SCRIPT_NAME.js"
            rm -f "/usr/local/cwpsrv/htdocs/resources/admin/include/imh-plugins.php"
            sed -i "/imh-plugins.php/d" "/usr/local/cwpsrv/htdocs/resources/admin/include/3rdparty.php" || true
            ;;
        *)
            rm -rf "/root/$SCRIPT_NAME"
            ;;
        esac
        echo -e "\033[0;32mUninstall complete.\033[0m"
        exit 0
    }
    uninstall_main
fi

# Function to check if command exists
command_exists() {
    command -v "$1" >/dev/null 2>&1
}

# Function to validate URL is accessible
validate_url() {
    local url=$1
    wget --spider -q "$url" 2>/dev/null || error_exit "Cannot access URL: $url"
}

# Function to download file with validation
download_file() {
    local url=$1
    local destination=$2
    [[ -d "$destination" ]] && error_exit "Destination is directory: $destination"

    local http_code=$(wget --server-response --spider "$url" 2>&1 | awk '/^  HTTP|^HTTP/{code=$2} END{print code}')
    [[ "$http_code" == "200" ]] || error_exit "HTTP $http_code: $url"

    wget -q -O "$destination" "$url" && [[ -s "$destination" ]] || error_exit "Download failed: $url"
}

download_file_with_checksum() {
    local url="$1"
    local destination="$2"
    download_file "$url" "$destination" || return 1
    download_file "${url}.sha256" "${destination}.sha256" || return 1
    cd "$(dirname "$destination")"
    sha256sum -c "$(basename "$destination").sha256" --status || { rm -f "$destination"*; error_exit "Checksum failed"; }
    print_message "$YELLOW" "Checksum OK: $(basename "$destination")"
}

copy_if_changed() {
    local src="$1" dest="$2"
    [[ -f "$dest" && cmp -s "$src" "$dest" ]] && { print_message "$GREEN" "No change: $dest"; return; }
    cp -p "$src" "$dest" && print_message "$YELLOW" "Updated: $dest"
}

create_directory() {
    local dir=$1 perms=${2:-755}
    [[ -d "$dir" ]] || { mkdir -p "$dir" && chmod "$perms" "$dir" && print_message "$GREEN" "Created: $dir"; }
}

# Function to install for cPanel
install_cpanel() {
    print_message "$YELLOW" "Installing for cPanel..."
    create_directory "/var/cpanel/apps"
    create_directory "/usr/local/cpanel/whostmgr/docroot/cgi/$SCRIPT_NAME"

    TEMP_DIR=$(mktemp -d) || error_exit "Temp dir failed"
    download_file_with_checksum "$BASE_URL/index.php" "$TEMP_DIR/index.php"
    download_file_with_checksum "$BASE_URL/$SCRIPT_NAME.conf" "$TEMP_DIR/$SCRIPT_NAME.conf"
    download_file_with_checksum "$BASE_URL/$SCRIPT_NAME.js" "$TEMP_DIR/$SCRIPT_NAME.js"
    download_file_with_checksum "$BASE_URL/$SCRIPT_NAME.png" "$TEMP_DIR/$SCRIPT_NAME.png"

    copy_if_changed "$TEMP_DIR/index.php" "/usr/local/cpanel/whostmgr/docroot/cgi/$SCRIPT_NAME/index.php"
    copy_if_changed "$TEMP_DIR/$SCRIPT_NAME.conf" "/usr/local/cpanel/whostmgr/docroot/cgi/$SCRIPT_NAME/$SCRIPT_NAME.conf"
    copy_if_changed "$TEMP_DIR/$SCRIPT_NAME.js" "/usr/local/cpanel/whostmgr/docroot/cgi/$SCRIPT_NAME/$SCRIPT_NAME.js"
    copy_if_changed "$TEMP_DIR/$SCRIPT_NAME.png" "/usr/local/cpanel/whostmgr/docroot/cgi/$SCRIPT_NAME/$SCRIPT_NAME.png"
    [[ -d "/usr/local/cpanel/whostmgr/docroot/addon_plugins" ]] && copy_if_changed "$TEMP_DIR/$SCRIPT_NAME.png" "/usr/local/cpanel/whostmgr/docroot/addon_plugins/$SCRIPT_NAME.png"

    chmod 755 "/usr/local/cpanel/whostmgr/docroot/cgi/$SCRIPT_NAME/index.php"
    /usr/local/cpanel/bin/register_appconfig "/usr/local/cpanel/whostmgr/docroot/cgi/$SCRIPT_NAME/$SCRIPT_NAME.conf" || error_exit "AppConfig failed"
}

# Function to install for CWP
install_cwp() {
    print_message "$YELLOW" "Installing for CWP..."
    [[ -d "/usr/local/cwpsrv/htdocs/resources/admin/modules" ]] || error_exit "CWP modules missing"

    TEMP_DIR=$(mktemp -d) || error_exit "Temp dir failed"
    download_file_with_checksum "$BASE_URL/$SCRIPT_NAME.php" "$TEMP_DIR/$SCRIPT_NAME.php"
    download_file_with_checksum "$BASE_URL/imh-plugins.php" "$TEMP_DIR/imh-plugins.php"
    download_file_with_checksum "$BASE_URL/$SCRIPT_NAME.png" "$TEMP_DIR/$SCRIPT_NAME.png"
    download_file_with_checksum "$BASE_URL/$SCRIPT_NAME.js" "$TEMP_DIR/$SCRIPT_NAME.js"

    command_exists chattr && chattr -ifR /usr/local/cwpsrv/htdocs/admin 2>/dev/null || true

    copy_if_changed "$TEMP_DIR/$SCRIPT_NAME.php" "/usr/local/cwpsrv/htdocs/resources/admin/modules/$SCRIPT_NAME.php"
    chmod 755 "/usr/local/cwpsrv/htdocs/resources/admin/modules/$SCRIPT_NAME.php"

    create_directory "/usr/local/cwpsrv/htdocs/admin/design/img" 755
    create_directory "/usr/local/cwpsrv/htdocs/admin/design/js" 755
    create_directory "/usr/local/cwpsrv/htdocs/resources/admin/include" 755

    copy_if_changed "$TEMP_DIR/$SCRIPT_NAME.png" "/usr/local/cwpsrv/htdocs/admin/design/img/$SCRIPT_NAME.png"
    copy_if_changed "$TEMP_DIR/$SCRIPT_NAME.js" "/usr/local/cwpsrv/htdocs/admin/design/js/$SCRIPT_NAME.js"
    copy_if_changed "$TEMP_DIR/imh-plugins.php" "/usr/local/cwpsrv/htdocs/resources/admin/include/imh-plugins.php"

    # Update 3rdparty.php safely
    local target="/usr/local/cwpsrv/htdocs/resources/admin/include/3rdparty.php"
    local include_file="/usr/local/cwpsrv/htdocs/resources/admin/include/imh-plugins.php"
    [[ -f "$target" ]] || { print_message "$YELLOW" "No 3rdparty.php (CWP menu manual)"; return; }
    if ! grep -q "imh-plugins.php" "$target"; then
        sed -i "1a include('$include_file');" "$target" || print_message "$YELLOW" "3rdparty.php update failed (manual OK)"
    fi
    print_message "$GREEN" "CWP menu updated"
}

install_plain() {
    print_message "$GREEN" "Plain install..."
    local dest="/root/$SCRIPT_NAME"
    create_directory "$dest" 700

    TEMP_DIR=$(mktemp -d)
    download_file_with_checksum "$BASE_URL/index.php" "$TEMP_DIR/index.php"
    download_file_with_checksum "$BASE_URL/$SCRIPT_NAME.js" "$TEMP_DIR/$SCRIPT_NAME.js"
    download_file_with_checksum "$BASE_URL/$SCRIPT_NAME.png" "$TEMP_DIR/$SCRIPT_NAME.png"

    copy_if_changed "$TEMP_DIR/index.php" "$dest/index.php"
    copy_if_changed "$TEMP_DIR/$SCRIPT_NAME.js" "$dest/$SCRIPT_NAME.js"
    copy_if_changed "$TEMP_DIR/$SCRIPT_NAME.png" "$dest/$SCRIPT_NAME.png"
    chmod 700 "$dest/index.php"
    print_message "$GREEN" "Files in $dest"
}

# Main
main() {
    print_message "$RED" "Installing $SCRIPT_NAME v$SCRIPT_VERSION..."
    check_root
    validate_url "$BASE_URL/index.php"

    local panel=$(detect_control_panel)
    case "$panel" in
    "cpanel") install_cpanel ;;
    "cwp") install_cwp ;;
    *) install_plain ;;
    esac

    print_message "$BLUE" "✅ Install complete! Run --uninstall to remove."
}

main "$@"
