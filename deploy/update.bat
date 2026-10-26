@echo off
rem WAU customer portal: get the latest version from GitHub and check it.
rem Run on the server after changes are pushed:  C:\wau-portal\deploy\update.bat
cd /d C:\wau-portal
git pull --ff-only origin main || (echo Update failed. Nothing was changed. & exit /b 1)
"C:\xampp\php\php.exe" C:\wau-portal\check.php
