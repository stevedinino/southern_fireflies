@echo off
setlocal
cd /d "%~dp0"
echo Starting Southern Fireflies local server at http://localhost:8000/
echo Press Ctrl+C to stop.
start "" http://localhost:8000/
php -S localhost:8000
