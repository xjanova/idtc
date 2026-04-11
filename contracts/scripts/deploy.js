const { ethers } = require("hardhat");
const fs = require("fs");
const path = require("path");

async function main() {
  const [deployer] = await ethers.getSigners();
  console.log("Deployer address:", deployer.address);

  const balance = await ethers.provider.getBalance(deployer.address);
  console.log("Deployer balance:", ethers.formatEther(balance), "POL");

  if (balance === 0n) {
    console.error("ERROR: Deployer has 0 POL. Fund the address first.");
    process.exit(1);
  }

  // ── Deploy IDTC ────────────────────────────────────────────────────────────
  console.log("\nDeploying IDTC...");
  const IDTC = await ethers.getContractFactory("IDTC");
  const idtc = await IDTC.deploy(deployer.address);
  await idtc.waitForDeployment();
  const idtcAddress = await idtc.getAddress();
  console.log("IDTC deployed to:", idtcAddress);

  // ── Deploy IDTCPresale ─────────────────────────────────────────────────────
  // usdPerPol = USD price of 1 POL (Polygon native token).
  // If 1 POL = $0.25, pass 0.25e18 = 250000000000000000
  //
  // Verification: 1 POL at $0.25, Private round ($0.04):
  //   tokensOut = (1e18 * 0.25e18 * 100) / (4 * 1e18) = 6.25e18 = 6.25 IDTC ✓
  const usdPerPol = ethers.parseEther("0.25");
  console.log("\nDeploying IDTCPresale...");
  console.log("Initial POL price: $0.25 USD (usdPerPol:", usdPerPol.toString(), ")");
  const Presale = await ethers.getContractFactory("IDTCPresale");
  const presale = await Presale.deploy(idtcAddress, deployer.address, usdPerPol);
  await presale.waitForDeployment();
  const presaleAddress = await presale.getAddress();
  console.log("IDTCPresale deployed to:", presaleAddress);

  // ── Approve presale to spend tokens ────────────────────────────────────────
  // Seed 10M + Private 15M + Public 25M = 50M total presale allocation (whitepaper)
  const presaleAlloc = 50_000_000n * 10n ** 18n;
  console.log("\nApproving IDTCPresale to transfer up to 50M IDTC (10M+15M+25M)...");
  const approveTx = await idtc.approve(presaleAddress, presaleAlloc);
  await approveTx.wait();
  console.log("Approval tx:", approveTx.hash);

  // ── Start Private round (currently live per sale page) ─────────────────────
  console.log("\nStarting Private round...");
  const startTx = await presale.startRound(2); // 2 = PRIVATE
  await startTx.wait();
  console.log("Private round started!");

  // ── Verify calculation ─────────────────────────────────────────────────────
  console.log("\n── Verification ──");
  console.log("If user sends 1 POL in Private round ($0.04):");
  const testTokens = await presale.tokensForPol(ethers.parseEther("1"));
  console.log("  tokensForPol(1 POL) =", ethers.formatEther(testTokens), "IDTC");
  console.log("  Expected: 6.25 IDTC (at $0.25/POL)");

  // ── Save deployment info ───────────────────────────────────────────────────
  const deployment = {
    network: "polygonAmoy",
    chainId: 80002,
    deployer: deployer.address,
    idtc: idtcAddress,
    idtcPresale: presaleAddress,
    usdPerPol: "0.25",
    deployedAt: new Date().toISOString()
  };

  const outPath = path.join(__dirname, "..", "deployment.json");
  fs.writeFileSync(outPath, JSON.stringify(deployment, null, 2));
  console.log("\nDeployment info saved to deployment.json");
  console.log(JSON.stringify(deployment, null, 2));
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
