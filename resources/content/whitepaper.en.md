# IDTC Whitepaper, Version 2

**Invest Energy-Development Coin (IDTC)** · IDTC Energy Capital · October 2026

> This document replaces the first whitepaper (April 2026) in full. The figures for the token, the contracts and the lock schedule can be verified from the contracts on TPIX Chain or from the sources cited. Anything that is still a plan is always labelled “plan”, and worked calculations are labelled as examples, not forecasts of returns.

## 1. Executive summary

IDTC is the digital token of the IDTC Energy Capital project, issued on TPIX Chain (Chain ID 4289). The project intends to raise funds from investors of every size to develop energy projects in Asia and the Middle East, starting with solar power, wind power and energy storage.

Version 2 differs from the first edition in four main ways:

- **Moved from Polygon to TPIX Chain.** TPIX Chain charges no gas fees, so holders can buy, sell and transfer without holding another coin in their wallet. The original presale contract on Polygon never had a single buyer, so the move affects no holder.
- **A supply of 1,100,000,000,000 IDTC (1.1 trillion)**, fixed forever, with no function to mint more.
- **Trust moves from promises into code.** 70% of the tokens are locked in an ownerless contract, the presale price is fixed in the contract, and purchase payments go straight to the project treasury, with the contract holding no funds at all.
- **Linking the token to the revenue of real energy projects (plan).** Once a project earns net revenue, part of it will be used to buy IDTC back from the market and send it to a burn address. This is not a dividend and no return is guaranteed (section 7).

Scale of the project: if the presale sells out, it raises at most **USD 150,000**. The project therefore starts at pilot scale, and will always state the real size of each project before any funds are used.

| Item | Value |
|---|---|
| Token name | Invest Energy-Development Coin |
| Symbol | IDTC |
| Chain | TPIX Chain · Chain ID 4289 |
| Standard | ERC-20 · 18 decimals |
| Total supply | 1,100,000,000,000 IDTC |
| Locked in contract | 770,000,000,000 IDTC (70%) · fully unlocked within 60 months |
| Presale price | USD 0.0000015 per IDTC · paid in USDT |
| Total value at presale price | USD 1,650,000 |
| Maximum raised in the presale | USD 150,000 (100,000,000,000 IDTC) |

## 2. The problem and the opportunity in energy

The International Energy Agency (IEA) estimates that global energy investment in 2025 will be around **USD 3.3 trillion**. Of this, about **USD 2.2 trillion** goes to renewables, nuclear, electricity grids, energy storage and energy efficiency, roughly twice the amount invested in oil, gas and coal. Solar investment alone is about USD 450 billion, the largest of any category, while battery energy storage exceeds USD 65 billion (source: IEA, *World Energy Investment 2025*, June 2025 · 2025 figures are preliminary estimates).

Large as the market is, direct investment in energy projects remains limited to financial institutions and large investors, because it requires large sums and long time horizons, and the use of funds is hard to verify.

What blockchain can genuinely solve is transparency and access; the returns of an energy project still depend on the project itself:

- Anyone can see token balances, transfers and the project wallets in real time on the explorer.
- Rules written as contracts enforce themselves, without having to trust the issuer.
- Small amounts can be bought, and trading is possible 24 hours a day.

## 3. Why TPIX Chain

TPIX Chain is an EVM-compatible chain running IBFT consensus with 4 validator nodes and a gas price set to 0. The on-chain components IDTC uses are:

| Component | Role |
|---|---|
| TPIX Token Factory | Creates IDTC as a standard token with no mint, pause or blacklist function |
| TPIX DEX | Uniswap V2-style AMM with a 0.3% pool fee, used for the IDTC/USDT and IDTC/TPIX pools |
| USDT on TPIX Chain | 6-decimal USDT, backed 1:1 by real USDT on BNB Chain |
| Blockscout explorer | Transactions, balances and verified source code at explorer.tpix.online |

Trade-offs holders should know: compared with large public chains, TPIX Chain is new, has few validators and starts with small liquidity. The plan to bring IDTC to BNB Chain (wIDTC) in section 9 is intended to widen access in this respect.

## 4. Tokenomics and allocation

| Allocation | Amount (IDTC) | Share | Terms |
|---|---:|---:|---|
| 1-year lock | 220,000,000,000 | 20.00% | Unlocks in months 13–24 |
| 2-year lock | 220,000,000,000 | 20.00% | Unlocks in months 25–36 |
| 3-year lock | 165,000,000,000 | 15.00% | Unlocks in months 37–48 |
| 4-year lock | 165,000,000,000 | 15.00% | Unlocks in months 49–60 |
| **Total locked** | **770,000,000,000** | **70.00%** | Held in the TrancheVesting contract |
| Presale | 100,000,000,000 | 9.09% | Sold at USD 0.0000015 for USDT |
| Operations, marketing and listings | 100,000,000,000 | 9.09% | Project treasury · spent under the published budget · includes referral bonuses (plan) of at most 5,000,000,000 |
| Liquidity reserve and pool top-up | 80,000,000,000 | 7.27% | Used only to deepen the pools, never to buy tokens to support or push the price |
| Initial liquidity | 50,000,000,000 | 4.55% | IDTC/USDT and IDTC/TPIX pools |
| **Total circulating** | **330,000,000,000** | **30.00%** | Available from day one |

The 30% circulating portion does not all enter the market at once. The presale portion is released only as buyers actually purchase, the liquidity portion sits in the pools, and the operations and reserve portions, together 180,000,000,000 IDTC (16.36% of the total supply), are held in project wallets that anyone can inspect and are covered by the usage rules in section 8.

What to know before deciding: the tokens the team can reach on day one are only the operations portion, the reserve portion and any liquidity portion not yet paired into the pools. Everything else is locked in the lock contract (unlocking on the schedule in section 5) or sits in the presale contract, which can only sell at the fixed price.

## 5. Lock contract and unlock schedule

770 billion IDTC are deposited in the **TrancheVesting** contract, which has the following properties:

- **No owner.** There is no function that lets anyone withdraw early, change the schedule or take tokens back.
- **Schedule fixed at deployment.** There are 4 tranches; each stays fully locked for its period, then unlocks in 12 equal installments.
- **Anyone can trigger a release once it is due**, but released tokens go only to the designated recipient wallet.
- **Verifiable at any time.** Anyone can query the locked amount, the released amount and the next unlock directly from the contract, and the website’s “Transparency” page reads these values live from the contract every time.

One period lasts 2,629,800 seconds (an average month: 365.25 days ÷ 12). The released amount is calculated as:

> Tranche released = tranche amount × (periods elapsed since the lock ended ÷ 12), capped at the tranche amount

| Period | Cumulative circulating supply | Monthly unlock |
|---|---:|---:|
| Launch day | 330 billion | — |
| Months 13–24 | up to 550 billion | 18.33 billion |
| Months 25–36 | up to 770 billion | 18.33 billion |
| Months 37–48 | up to 935 billion | 13.75 billion |
| Months 49–60 | up to 1.1 trillion | 13.75 billion |

No month unlocks more than 1.67% of the total supply. This spreads out any selling pressure from unlocks: there is no “big unlock day”.

**Where released tokens go.** The contract sends released tokens to the recipient wallet (address in section 11). The recipient can be changed in two steps, for example to a multisig, started by the current recipient, and nobody else can change it. The contract does not dictate what the released tokens are used for, so there is an additional rule (plan): the project will announce its plan for each released installment at least 30 days in advance.

## 6. Presale and liquidity

The presale uses the **FixedPriceSale** contract, which has the following properties:

- **Fixed, unchangeable price.** The contract rate is 1 USDT = 666,666.67 IDTC (USD 0.0000015 per IDTC), and there is no function to change the price.
- **Total cap of 100 billion IDTC.** The contract cannot sell more than this.
- **Instant delivery.** Buyers receive IDTC in the same transaction in which they pay USDT; there is no claim step.
- **The contract does not hold buyers’ funds.** The USDT paid goes to the project treasury in the same transaction.
- **What the operator can do:** pause the sale, adjust the minimum purchase and the per-wallet limit, adjust the sale window, and withdraw unsold tokens only after the round closes or while the sale is paused. Every such action is recorded on-chain and can be audited.

**Liquidity.** The project opens IDTC/USDT and IDTC/TPIX pools at the presale price with small seed capital (about 10 USDT per side), then adds to them from presale proceeds. Buyers should understand that small pools allow the DEX price to swing widely when large amounts are traded. While the presale remains open, its fixed price is the option without slippage.

**Liquidity commitments (plan)**

- Put **10%** of the USDT received from the presale into the two pools, split equally, at the presale price.
- **Lock the liquidity tokens (LP tokens) for at least 12 months** in a lock contract, so the team cannot withdraw the pools early.
- Every 1 USDT added to a pool must be paired with 666,666.67 IDTC. If the presale sells out, 15,000 USDT can therefore be paired with about 10,000,000,000 IDTC. The rest of the 50,000,000,000 IDTC waits to be paired as more USDT becomes available, and stays in the project wallet under the same rules as the reserve.

## 7. Token use and how value is created

IDTC gives no right to interest, dividends or a fixed share of profit. The token’s value comes from three sources: transparency that can be verified, real use once projects exist, and a smaller supply funded by project revenue. The table separates what works at launch from what is still a plan.

| Mechanism | How it works | Status |
|---|---|---|
| Buy, sell, transfer | Buy in the fixed-price presale or swap on TPIX DEX 24 hours a day, with no gas fees (swaps on the pools pay the 0.3% pool fee) | Available at launch |
| Verify everything | The locked amount, unlock schedule and project wallets can be viewed on the explorer and on the “Transparency” page | Available at launch |
| Buy back and burn | Use part of the project’s net revenue to buy IDTC from the pool and send it to a burn address | Plan |
| Pay and get discounts on services | Use IDTC to pay for, or get discounts on, energy services provided by IDTC’s own projects | Plan |
| Advisory voting | Holders rank the projects the team shortlists | Plan |
| Referral program | A one-level bonus for whoever refers a presale buyer | Plan |

### Buy back and burn (plan)

This mechanism links the revenue of the energy projects to the number of tokens, under these rules:

- **It starts only when there is real net revenue.** While the projects earn nothing, nothing is bought back.
- **10%–30% of each quarter’s net revenue** (after operating costs, tax and the reserve fund). Each quarter’s rate is announced in advance and always lies within this range.
- **Purchases are spread out**, at least 4 per quarter and never more than 5% of the USDT in the pool per purchase, so the price does not jump. Anything that cannot be bought carries over to the next quarter.
- **Tokens are sent to the burn address** `0x000000000000000000000000000000000000dEaD`, which nobody can access. IDTC stays a standard token with no burn function, and every transaction hash is published in the quarterly report.
- **This is not a dividend.** No holder has any claim on the project’s money, and no buyback may happen at all if there is no revenue.

Worked example (not a forecast): if the project earns net revenue of USD 100,000 in a year and allocates 20% of it, USD 20,000, then at USD 0.0000015 per IDTC it buys 13,333,333,333 IDTC, or 1.21% of the total supply. If the market price is higher, it buys fewer. The example shows that buybacks are a small support compared with the total supply, and holders should not expect them to be the main driver of the price.

### Pay and get discounts on services (plan)

When the first project starts selling electricity or energy services, the project will let people pay for its own services in IDTC or get a discount for doing so. The discount rate and conditions will be announced together with that project. No discount or benefit is available before a real project exists.

### Advisory voting (plan)

When there are several possible projects, the team will shortlist those that pass an initial review, with documentation, and holders above the announced minimum balance will rank them. The result is an opinion to inform the decision, not legally binding, but the team will publish its reasons every time it decides differently from the vote.

### Referral program (plan)

- A referrer receives a **5%** bonus on the IDTC that the referred person buys in the presale, paid from the operations and marketing portion, not from the buyer’s money, and with no new tokens issued.
- **One level only.** There is no bonus passed up the chain from people the referred person refers in turn.
- Paid once the purchase transaction is 7 days old, to check it was not a self-referral.
- The program is capped at **5,000,000,000 IDTC** in total (0.45% of the total supply), exactly 5% of the presale cap.

### What IDTC does not do

- It pays no staking interest or fixed dividend.
- It does not guarantee profit from price differences, and the project does not trade with its own funds to create volume or push the price.
- It has no multi-level bonuses.
- It announces no discount or benefit that has no real project behind it.

## 8. Governance and use of funds

Planned use of funds raised, adjusted from version 1 by adding a 10% liquidity portion (the dollar figures assume a full presale of USD 150,000):

| Category | Share | If the presale sells out (USD) |
|---|---:|---:|
| Energy project investment and operations | 40% | 60,000 |
| Platform and technology development | 20% | 30,000 |
| Marketing and strategic partnerships | 15% | 22,500 |
| Pool liquidity (LP locked for at least 12 months) | 10% | 15,000 |
| Legal, operations and reserve fund | 15% | 22,500 |

The energy portion is therefore at pilot scale. The project may co-invest with partners to scale up, but will not name partners or state project sizes until there is a contract that can be disclosed.

What is already done and will exist as soon as the contracts are on-chain:

- Disclosed the project wallet addresses and all contracts in section 11
- Published and verified the source code of every contract on the explorer
- The website’s back office holds no private keys: every on-chain action is signed from the operator’s own wallet and logged
- The contracts pass an internal test suite covering every time boundary of the unlock schedule and the presale purchase

Commitments still to be delivered (plan):

- **Make the presale proceeds wallet a multisig before the sale opens** (at least 2 of 3 signers), and move the unlock recipient wallet to a multisig as well.
- **Announce each energy project with documentation at least 14 days before funds are used.** The documents must give the site, capacity, budget, counterparties and the people responsible.
- **Quarterly reports** covering use of funds in every category, the transaction hashes of buybacks and burns, and movements of the project wallets.
- **Announce the plan for unlocked tokens 30 days in advance**, as in section 5.
- **If no first energy project is announced with documentation within 12 months after the presale closes**, the unused energy portion will be used to buy IDTC back from the market and send it to the burn address.
- **Commission an independent contract audit.** The contracts have only been through internal testing so far and there is no report from an outside auditor yet.

## 9. Roadmap

| Phase | Status | Details |
|---|---|---|
| Foundations | Done | Project and website launched (April 2026) · TPIX Chain · TPIX DEX · Token Factory |
| Launch on TPIX Chain | In progress | IDTC created through the Token Factory · 70% lock contract · USDT presale · IDTC/USDT and IDTC/TPIX pools · new website |
| Conditions before the sale opens | Plan | Presale proceeds wallet as a multisig · legal opinion on the offering · locked LP tokens |
| Wider access | Plan | Automated USDT bridge from BNB Chain · applications for CoinGecko and CoinMarketCap listings · wIDTC on BNB Chain and PancakeSwap |
| Energy projects | Plan | First project announced with documentation · quarterly reports · independent contract audit |
| Outcomes for holders | Plan | Buy back and burn once there is real net revenue · pay and get discounts on services · advisory voting · referral program |

## 10. Risks and legal notice

**Risks holders must understand**

- **Price.** Digital assets are highly volatile; the price of IDTC may fall until it is worthless.
- **Liquidity.** The initial pools are small; selling a large amount may be difficult or may achieve a lower price than expected.
- **Selling pressure from the project side.** The project wallets hold 180,000,000,000 IDTC and receive the unlocked tokens on schedule. If the project sells a large amount, the price may fall. The commitments in sections 5 and 8 reduce this risk but do not remove it.
- **Returns.** No return of any kind is guaranteed. Buy back and burn depends on the project’s net revenue, which may never arise, and when it does it is a small support, as the example in section 7 shows.
- **Technology.** Smart contracts may contain undiscovered bugs. There is no report from an independent contract auditor yet, and TPIX Chain is new and has few validators.
- **Cross-chain bridge.** USDT on TPIX Chain depends on backing by real USDT on BNB Chain.
- **Execution.** Energy projects take a long time and may not go according to plan. The maximum raise of USD 150,000 is pilot scale.
- **Regulation.** Laws on digital assets can change and differ from country to country.

**Legal notice.** IDTC is not a deposit, not a licensed security, and carries no guarantee of any return. This document is not investment advice and is not an offer of sale in any jurisdiction where such an offer is prohibited by law. In Thailand, digital asset businesses and public offerings of digital tokens are governed by the Emergency Decree on Digital Asset Businesses B.E. 2561 (2018) and the notifications of the Thai Securities and Exchange Commission (SEC). Because the project involves putting money into energy projects, it will seek a legal opinion before offering to the general public, on whether IDTC and the mechanisms in section 7 count as an investment token, and will publish the outcome on the website (plan). Anyone interested should study the laws of their own country and consult a qualified professional before making a decision.

## 11. All contract addresses

{{contracts_table}}

Verify every address at explorer.tpix.online · Chain ID 4289 · RPC https://rpc1.tpix.online

## Appendix: What changed from version 1

| Topic | Version 1 (April 2026) | Version 2 (October 2026) |
|---|---|---|
| Chain | Polygon (137) | TPIX Chain (4289) |
| Supply | 100,000,000 IDTC | 1,100,000,000,000 IDTC |
| Sale | 3 rounds at USD 0.03–0.05, paid in POL | A single round at a fixed USD 0.0000015, paid in USDT |
| Lock | Stated in text | 70% locked in an ownerless contract, fully unlocked within 60 months |
| Token delivery | After the round, under a vesting schedule | Immediately, in the same transaction as payment |
| Staking returns / dividends | Stated | Removed, replaced by a buyback and burn tied to real net revenue (plan), with no return guaranteed |
| Split of funds | 40% / 25% / 20% / 15% | 40% / 20% / 15% / 10% (liquidity) / 15% |
