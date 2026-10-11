const { ethers } = require("ethers");
const fs = require("fs");
const path = require("path");

const wallet = ethers.Wallet.createRandom();
console.log("Address:", wallet.address);
console.log("Private Key:", wallet.privateKey);

// Write to .env
const envPath = path.join(__dirname, "..", ".env");
const envContent = `PRIVATE_KEY=${wallet.privateKey}\nDEPLOYER_ADDRESS=${wallet.address}\nPOLYGONSCAN_API_KEY=\n`;
fs.writeFileSync(envPath, envContent, { flag: "w" });
console.log(".env written to", envPath);
