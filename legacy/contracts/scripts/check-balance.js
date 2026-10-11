const { ethers } = require("ethers");
require("dotenv").config({ path: require("path").join(__dirname, "../.env") });

async function main() {
  const provider = new ethers.JsonRpcProvider("https://rpc-amoy.polygon.technology");
  const address = process.env.DEPLOYER_ADDRESS;
  if (!address) {
    console.error("DEPLOYER_ADDRESS not set in .env");
    process.exit(1);
  }
  const balance = await provider.getBalance(address);
  console.log(`Address: ${address}`);
  console.log(`Balance: ${ethers.formatEther(balance)} MATIC`);
  if (balance === 0n) {
    console.log("\nFund this address with at least 0.5 MATIC from:");
    console.log("  https://faucet.polygon.technology");
    console.log("  https://www.alchemy.com/faucets/polygon-amoy");
  } else {
    console.log("\nReady to deploy!");
  }
}

main().catch(console.error);
