# Script to launch the AI Sentiment Analysis & Driver Review Classifier Service
$ErrorActionPreference = "Stop"

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$projectRoot = Split-Path -Parent $scriptDir
$aiDir = Join-Path $projectRoot "ai-service"

$pythonExe = Join-Path $aiDir "venv\Scripts\python.exe"

if (-not (Test-Path $pythonExe)) {
    Write-Host "Warning: Virtual environment not found at $pythonExe, falling back to system python..." -ForegroundColor Yellow
    $pythonExe = "python"
}

Write-Host "Starting AI Review Classifier Service on http://127.0.0.1:8001..." -ForegroundColor Green
Set-Location $aiDir
& $pythonExe -m uvicorn inference_api:app --host 127.0.0.1 --port 8001 --app-dir $aiDir
