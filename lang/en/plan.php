<?php

// Token-plan and contract labels — every figure comes from config/idtc.php (:price, :amount, :count, :rate)

return [
    'tranche_years' => ':n-year lock',
    'tranche_months' => ':n-month lock',

    'group' => [
        'locked' => 'Locked 70% · unlocks on schedule',
        'liquid' => 'Circulating 30% · available from day one',
    ],

    'alloc' => [
        'lock_1y' => ['name' => '1-year lock', 'note' => 'Unlocks in months 13–24'],
        'lock_2y' => ['name' => '2-year lock', 'note' => 'Unlocks in months 25–36'],
        'lock_3y' => ['name' => '3-year lock', 'note' => 'Unlocks in months 37–48'],
        'lock_4y' => ['name' => '4-year lock', 'note' => 'Unlocks in months 49–60'],
        'presale' => ['name' => 'Presale', 'note' => 'Sold at $:price for USDT'],
        'operations' => ['name' => 'Operations, marketing and listings', 'note' => 'Project treasury'],
        'reserve' => ['name' => 'Liquidity reserve and pool top-up', 'note' => 'Deepens the pools only, never props up the price'],
        'liquidity' => ['name' => 'Initial liquidity', 'note' => 'IDTC/USDT and IDTC/TPIX pools'],
    ],

    'table' => [
        'contract' => 'Contract',
        'role' => 'Role',
        'address' => 'Address on TPIX Chain',
    ],

    'contracts' => [
        'token' => ['name' => 'IDTC token', 'role' => 'ERC-20 · 18 decimals · no mint, pause or blacklist'],
        'vesting' => ['name' => '70% lock contract', 'role' => 'Locks :amount IDTC in :count tranches · ownerless'],
        'presale' => ['name' => 'Presale', 'role' => 'Fixed price 1 USDT = :rate IDTC · USDT goes straight to the treasury'],
        'usdt' => ['name' => 'USDT on TPIX Chain', 'role' => '6-decimal USDT · backed 1:1 by USDT on BNB Chain'],
        'wtpix' => ['name' => 'Wrapped TPIX', 'role' => 'TPIX as an ERC-20 for TPIX DEX pools'],
        'dex_factory' => ['name' => 'TPIX DEX Factory', 'role' => 'Creates Uniswap V2-style swap pools'],
        'dex_router' => ['name' => 'TPIX DEX Router', 'role' => 'Swaps and liquidity provision'],
        'pair_usdt' => ['name' => 'IDTC/USDT pool', 'role' => 'IDTC/USDT liquidity'],
        'pair_tpix' => ['name' => 'IDTC/TPIX pool', 'role' => 'IDTC/WTPIX liquidity'],
    ],
];
