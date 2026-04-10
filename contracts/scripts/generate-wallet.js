import { ethers } from "ethers";
import { writeFileSync } from "fs";
import { join, dirname } from "path";
import { fileURLToPath } from "url";

const __dirname = dirname(fileURLToPath(import.meta.url));

const wallet = ethers.Wallet.createRandom();
console.log("Address:", wallet.address);
console.log("Private Key:", wallet.privateKey);

// Write to .env (only if not already present)
const envPath = join(__dirname, "..", ".env");
const envContent = `PRIVATE_KEY=${wallet.privateKey}\nDEPLOYER_ADDRESS=${wallet.address}\nPOLYGONSCAN_API_KEY=\n`;
writeFileSync(envPath, envContent, { flag: "w" });
console.log(".env written to", envPath);
