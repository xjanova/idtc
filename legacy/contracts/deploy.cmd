@echo off
echo Checking balance...
node scripts/check-balance.js
echo.
echo Starting deployment to Polygon Amoy...
node node_modules/hardhat/internal/cli/bootstrap.js run scripts/deploy.js --network amoy
echo.
echo Done. Check deployment.json for contract addresses.
pause
