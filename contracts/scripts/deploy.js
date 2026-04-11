const { ethers } = require("hardhat");
const fs = require("fs");
const path = require("path");

async function main() {
  const [deployer] = await ethers.getSigners();
  console.log("Deployer address:", deployer.address);

  const balance = await ethers.provider.getBalance(deployer.address);
  console.log("Deployer balance:", ethers.formatEther(balance), "MATIC");

  if (balance === 0n) {
    console.error("ERROR: Deployer has 0 MATIC. Fund the address first.");
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
  // Initial rate: 1 MATIC = $0.87 → maticPerUsd = 1/0.87 ≈ 1.1494e18
  const maticPerUsd = ethers.parseEther("1.1494");
  console.log("\nDeploying IDTCPresale...");
  const Presale = await ethers.getContractFactory("IDTCPresale");
  const presale = await Presale.deploy(idtcAddress, deployer.address, maticPerUsd);
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

  // ── Save deployment info ───────────────────────────────────────────────────
  const deployment = {
    network: "polygonAmoy",
    chainId: 80002,
    deployer: deployer.address,
    idtc: idtcAddress,
    idtcPresale: presaleAddress,
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
