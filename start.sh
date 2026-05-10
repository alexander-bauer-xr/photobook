#!/bin/bash
#
# Photobook App - Quick Start Script
# Usage: ./start.sh [--install] [--help]
#

set -e

cd "$(dirname "$0")"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

print_header() {
    echo -e "${BLUE}"
    echo "╔═══════════════════════════════════════════════════════════════╗"
    echo "║              📚 Photobook Generator                           ║"
    echo "╚═══════════════════════════════════════════════════════════════╝"
    echo -e "${NC}"
}

print_status() {
    echo -e "${GREEN}✓${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}⚠${NC} $1"
}

print_error() {
    echo -e "${RED}✗${NC} $1"
}

check_dependencies() {
    local missing=0
    
    echo "Checking dependencies..."
    
    # Check PHP
    if command -v php &> /dev/null; then
        local php_version=$(php -v | head -n 1 | grep -oP 'PHP \K[0-9]+\.[0-9]+')
        print_status "PHP $php_version found"
    else
        print_error "PHP not found"
        missing=1
    fi
    
    # Check Composer
    if command -v composer &> /dev/null; then
        print_status "Composer found"
    else
        print_error "Composer not found"
        missing=1
    fi
    
    # Check Node.js
    if command -v node &> /dev/null; then
        local node_version=$(node -v)
        print_status "Node.js $node_version found"
    else
        print_error "Node.js not found"
        missing=1
    fi
    
    # Check npm
    if command -v npm &> /dev/null; then
        print_status "npm found"
    else
        print_error "npm not found"
        missing=1
    fi
    
    # Optional: Check Ghostscript for CMYK conversion
    if command -v gs &> /dev/null; then
        print_status "Ghostscript found (CMYK conversion available)"
    else
        print_warning "Ghostscript not found (CMYK conversion unavailable)"
    fi
    
    echo ""
    
    if [ $missing -eq 1 ]; then
        print_error "Missing required dependencies. Please install them and try again."
        exit 1
    fi
}

install_dependencies() {
    echo "Installing PHP dependencies..."
    composer install --no-interaction --prefer-dist
    echo ""
    
    echo "Installing Node.js dependencies..."
    npm install
    echo ""
    
    # Copy .env if not exists
    if [ ! -f .env ]; then
        if [ -f .env.example ]; then
            cp .env.example .env
            print_status "Created .env from .env.example"
            
            # Generate app key
            php artisan key:generate --no-interaction
            print_status "Generated application key"
        else
            print_warning ".env.example not found, skipping .env setup"
        fi
    else
        print_status ".env already exists"
    fi
    
    echo ""
}

run_migrations() {
    echo "Running database migrations..."
    php artisan migrate --force
    echo ""
}

start_services() {
    echo -e "${GREEN}Starting services...${NC}"
    echo ""
    echo "  📦 PHP Server     → http://localhost:8000"
    echo "  ⚡ Vite Dev       → http://localhost:5173"
    echo "  📝 Queue Worker   → Processing jobs"
    echo ""
    echo -e "${YELLOW}Press Ctrl+C to stop all services${NC}"
    echo ""
    
    # Use the existing npm script
    npm run dev:all
}

show_help() {
    echo "Usage: ./start.sh [OPTIONS]"
    echo ""
    echo "Options:"
    echo "  --install    Install all dependencies before starting"
    echo "  --check      Check dependencies only"
    echo "  --help       Show this help message"
    echo ""
    echo "Quick start:"
    echo "  ./start.sh              Start the development server"
    echo "  ./start.sh --install    Full setup + start"
    echo ""
    echo "The app will be available at:"
    echo "  http://localhost:8000/photobook/editor"
    echo ""
}

# Main
print_header

case "${1:-}" in
    --help|-h)
        show_help
        exit 0
        ;;
    --check)
        check_dependencies
        exit 0
        ;;
    --install)
        check_dependencies
        install_dependencies
        run_migrations
        start_services
        ;;
    "")
        check_dependencies
        start_services
        ;;
    *)
        print_error "Unknown option: $1"
        echo ""
        show_help
        exit 1
        ;;
esac
