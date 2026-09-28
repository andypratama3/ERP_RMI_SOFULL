@echo off
setlocal enabledelayedexpansion
set GRADLE_VERSION=8.8
set ROOT_DIR=%~dp0
set CACHE_DIR=%ROOT_DIR%\.gradle-wrapper-cache
set DIST_ZIP=%CACHE_DIR%\gradle-%GRADLE_VERSION%-bin.zip
set DIST_DIR=%CACHE_DIR%\gradle-%GRADLE_VERSION%

if not exist "%DIST_DIR%" (
  if not exist "%CACHE_DIR%" mkdir "%CACHE_DIR%"
  if not exist "%DIST_ZIP%" (
    powershell -NoProfile -ExecutionPolicy Bypass -Command "Invoke-WebRequest -Uri https://services.gradle.org/distributions/gradle-%GRADLE_VERSION%-bin.zip -OutFile '%DIST_ZIP%'"
  )
  powershell -NoProfile -ExecutionPolicy Bypass -Command "Expand-Archive -Path '%DIST_ZIP%' -DestinationPath '%CACHE_DIR%' -Force"
)

"%DIST_DIR%\bin\gradle.bat" -p "%ROOT_DIR%" %*
