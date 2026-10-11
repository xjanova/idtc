/**
 * Verify IDTC contracts on Polygonscan via Etherscan V2 API
 *
 * Usage:
 *   node verify-polygonscan.js YOUR_ETHERSCAN_API_KEY
 *
 * Get a free API key at: https://etherscan.io/register
 * One key works for ALL chains (Polygon, ETH, BSC, etc.)
 */

const fs = require('fs');
const https = require('https');
const path = require('path');

const API_KEY = process.argv[2];
if (!API_KEY) {
  console.error('Usage: node verify-polygonscan.js YOUR_API_KEY');
  console.error('Get free key at: https://etherscan.io/register');
  process.exit(1);
}

const CHAIN_ID = 137; // Polygon Mainnet

const contracts = [
  {
    name: 'IDTC Token',
    address: '0x0162847f552EA0cCc4E9eE2b39F36EcD45253815',
    sourceFile: 'IDTC_flattened.sol',
    contractName: 'IDTC',
    constructorArgs: '0000000000000000000000000b263d083969946fa2bb44af2deba69d3d3d0220',
  },
  {
    name: 'IDTCPresale',
    address: '0x99448C18B1031C0eD5FC3512cbEBDD1C2E0B1c2f',
    sourceFile: 'IDTCPresale_flattened.sol',
    contractName: 'IDTCPresale',
    constructorArgs: '0000000000000000000000000162847f552ea0ccc4e9ee2b39f36ecd452538150000000000000000000000000b263d083969946fa2bb44af2deba69d3d3d022000000000000000000000000000000000000000000000000003782dace9d90000',
  },
];

function postAPI(params) {
  return new Promise((resolve, reject) => {
    const postData = new URLSearchParams(params).toString();
    const url = `https://api.etherscan.io/v2/api?chainid=${CHAIN_ID}&apikey=${API_KEY}`;

    const options = {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'Content-Length': Buffer.byteLength(postData),
      },
    };

    const req = https.request(url, options, (res) => {
      let data = '';
      res.on('data', (chunk) => data += chunk);
      res.on('end', () => {
        try { resolve(JSON.parse(data)); }
        catch (e) { reject(new Error('Invalid response: ' + data.slice(0, 200))); }
      });
    });
    req.on('error', reject);
    req.write(postData);
    req.end();
  });
}

function getAPI(params) {
  return new Promise((resolve, reject) => {
    const qs = new URLSearchParams(params).toString();
    const url = `https://api.etherscan.io/v2/api?chainid=${CHAIN_ID}&apikey=${API_KEY}&${qs}`;

    https.get(url, (res) => {
      let data = '';
      res.on('data', (chunk) => data += chunk);
      res.on('end', () => {
        try { resolve(JSON.parse(data)); }
        catch (e) { reject(new Error('Invalid response: ' + data.slice(0, 200))); }
      });
    }).on('error', reject);
  });
}

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

async function verifyContract(contract) {
  console.log(`\n${'='.repeat(60)}`);
  console.log(`Verifying: ${contract.name}`);
  console.log(`Address:   ${contract.address}`);
  console.log(`${'='.repeat(60)}`);

  // Read flattened source
  const sourcePath = path.join(__dirname, contract.sourceFile);
  if (!fs.existsSync(sourcePath)) {
    console.error(`ERROR: ${contract.sourceFile} not found. Run: npx hardhat flatten`);
    return false;
  }
  const sourceCode = fs.readFileSync(sourcePath, 'utf-8');
  console.log(`Source: ${contract.sourceFile} (${sourceCode.length} chars)`);

  // Submit verification
  const params = {
    module: 'contract',
    action: 'verifysourcecode',
    contractaddress: contract.address,
    sourceCode: sourceCode,
    codeformat: 'solidity-single-file',
    contractname: contract.contractName,
    compilerversion: 'v0.8.24+commit.e11b9ed9',
    optimizationUsed: '0',
    runs: '200',
    constructorArguements: contract.constructorArgs, // yes, Etherscan API has this typo
    evmversion: '',
    licenseType: '3', // MIT
  };

  console.log('Submitting verification...');
  const submitResult = await postAPI(params);
  console.log('Response:', JSON.stringify(submitResult));

  if (submitResult.status !== '1') {
    console.error(`FAILED: ${submitResult.result || submitResult.message}`);
    // Check if already verified
    if (String(submitResult.result).includes('Already Verified')) {
      console.log('Contract is already verified!');
      return true;
    }
    return false;
  }

  const guid = submitResult.result;
  console.log(`GUID: ${guid}`);
  console.log('Waiting for verification...');

  // Poll for result
  for (let i = 0; i < 20; i++) {
    await sleep(5000);
    const checkResult = await getAPI({
      module: 'contract',
      action: 'checkverifystatus',
      guid: guid,
    });
    console.log(`Check ${i + 1}: ${checkResult.result}`);

    if (checkResult.result === 'Pass - Verified') {
      console.log(`SUCCESS! ${contract.name} verified on Polygonscan!`);
      console.log(`View: https://polygonscan.com/address/${contract.address}#code`);
      return true;
    }
    if (checkResult.result === 'Fail - Unable to verify' ||
        checkResult.result === 'Already Verified') {
      console.log(`Result: ${checkResult.result}`);
      return checkResult.result === 'Already Verified';
    }
  }
  console.error('Timeout waiting for verification');
  return false;
}

async function main() {
  console.log('Etherscan V2 API — Contract Verification');
  console.log(`Chain: Polygon (${CHAIN_ID})`);
  console.log(`API Key: ${API_KEY.slice(0, 6)}...${API_KEY.slice(-4)}`);

  for (const contract of contracts) {
    try {
      await verifyContract(contract);
    } catch (err) {
      console.error(`Error verifying ${contract.name}:`, err.message);
    }
  }
  console.log('\nDone!');
}

main();
