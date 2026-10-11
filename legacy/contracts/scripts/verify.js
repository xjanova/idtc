/**
 * Verify deployed contracts on Polygonscan Amoy.
 * Run after deployment: node scripts/verify.js
 * Requires POLYGONSCAN_API_KEY in .env
 */
const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");

const deploymentPath = path.join(__dirname, "../deployment.json");
if (!fs.existsSync(deploymentPath)) {
  console.error("deployment.json not found. Deploy first.");
  process.exit(1);
}

const deployment = JSON.parse(fs.readFileSync(deploymentPath, "utf8"));
const HH = "node node_modules/hardhat/internal/cli/bootstrap.js";

console.log("Verifying IDTC at", deployment.idtc);
try {
  execSync(
    `${HH} verify --network amoy ${deployment.idtc} "${deployment.deployer}"`,
    { stdio: "inherit", cwd: path.join(__dirname, "..") }
  );
} catch (e) {
  console.error("IDTC verification failed (may already be verified):", e.message);
}

console.log("\nVerifying IDTCPresale at", deployment.idtcPresale);
try {
  // Constructor args: idtcAddress, ownerAddress, maticPerUsd (1.25e18 = 1250000000000000000)
  execSync(
    `${HH} verify --network amoy ${deployment.idtcPresale} "${deployment.idtc}" "${deployment.deployer}" "1250000000000000000"`,
    { stdio: "inherit", cwd: path.join(__dirname, "..") }
  );
} catch (e) {
  console.error("IDTCPresale verification failed:", e.message);
}
