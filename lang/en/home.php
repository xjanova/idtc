<?php

// Home page — strings with HTML (<b>, <span class="grad">) are printed with {!! !!} in home.blade.php (static text from this file only)

return [
    'hero' => [
        'aria' => 'Featured',
        'slide_of' => ':n of :total',
        'prev' => 'Previous slide',
        'next' => 'Next slide',
        'pick' => 'Choose a slide',
        'dot' => 'Slide :n',
        'slides' => [
            [
                'alt' => 'solar farm and substation at night',
                'eyebrow' => 'Presale on TPIX Chain · zero gas fees',
                'title' => 'Invest in energy development',
                'grad' => 'with a token you can verify line by line',
                'lead' => 'IDTC is the token of the IDTC Energy Capital project on TPIX Chain. <b>70% of the supply is locked in a contract that no one can withdraw from early.</b> The presale price is fixed in the contract, and every trade is visible on the explorer.',
                'cta' => 'Buy IDTC in the presale',
                'cta2' => 'Read the whitepaper',
            ],
            [
                'alt' => 'offshore wind farm at dawn',
                'eyebrow' => '770 billion IDTC locked · 5 years',
                'title' => '70% locked in an ownerless contract',
                'grad' => 'with no large one-off unlock',
                'lead' => 'Split into 4 tranches locked for 1–4 years, each <b>unlocking monthly over the following year</b>. At most 1.67% of supply unlocks in any month, and anyone can check the remaining balance at any time.',
                'cta' => 'View the live lock contract',
                'cta2' => 'Unlock schedule',
            ],
            [
                'alt' => 'desert solar power plant in the morning',
                'eyebrow' => 'Asia · Middle East',
                'title' => 'The global energy market:',
                'grad' => 'USD 3.3 trillion a year',
                'lead' => 'Global energy investment is around USD 3.3 trillion in 2025 (IEA), <b>yet most people have no way to take part</b>. IDTC aims to open access to investors of every size, starting with solar, wind and energy storage.',
                'cta' => 'Project goals',
                'cta2' => 'Read the whitepaper',
            ],
            [
                'alt' => 'a city at night powered by clean energy',
                'eyebrow' => 'Pay in USDT · receive tokens instantly',
                'title' => 'Buy with USDT,',
                'grad' => 'add the chain in one click',
                'lead' => 'One price for everyone: <b>$:price per IDTC</b>. Hold USDT on BNB Chain? Bridge it to TPIX Chain 1:1, then add the chain and the token to your wallet on desktop or mobile.',
                'cta' => 'Go to the presale',
                'cta2' => 'Add chain + token',
            ],
        ],
    ],

    'dock' => [
        'per' => 'per IDTC · 1 USDT = :rate IDTC',
        'sold' => 'Sold in the presale',
        'soon' => 'Presale opening soon',
        'pct_of' => '% of :cap IDTC',
        'raised' => 'Raised $',
        'waiting' => 'Awaiting the presale contract',
    ],

    'stats' => [
        'aria' => 'Key figures',
        'supply' => 'Total supply',
        'locked' => 'Locked in contract',
        'value' => 'Total value at presale price',
        'gas' => 'Gas fee on TPIX Chain',
    ],

    'video' => [
        'kicker' => 'Explainer video',
        'title' => 'IDTC in <span class="grad">2 minutes</span>',
        'lead' => 'The energy-development token on TPIX Chain, the 70% lock contract and the fixed-price presale, explained in one video · English narration · Thai subtitles',
        'play' => 'Play the IDTC explainer video',
        'replay' => 'Replay the video',
        'watch' => 'Watch · 2:14',
        'again' => 'Watch again',
        'caption' => 'The presenter in this video is AI-generated · every figure in the video matches the contracts and the whitepaper',
    ],

    'about' => [
        'title' => 'A token built for <span class="grad">energy development</span>',
        'lead' => 'The project aims to raise funds from investors of every size to develop energy projects in Asia and the Middle East. Every project that will use the funds must first be announced, with documents that can be verified.',
        'sectors' => [
            'solar' => ['title' => 'Solar power', 'text' => 'Solar farms and rooftop solar, where unit costs have fallen fastest in the energy sector.', 'alt' => 'solar panels in the last light of day'],
            'wind' => ['title' => 'Wind power', 'text' => 'Wind turbines in areas with steady wind, generating day and night.', 'alt' => 'wind turbines on a ridge at dusk'],
            'storage' => ['title' => 'Energy storage and grids', 'text' => 'Grid-scale batteries that store solar and wind power for when it is actually needed.', 'alt' => 'battery energy storage system'],
        ],
    ],

    'trust' => [
        'kicker' => 'Trust you can verify',
        'title' => 'What IDTC’s contracts <span class="grad">cannot do</span>, not even for the team',
        'lead' => 'Promises on paper can change; code on-chain cannot. Every point below can be checked against the verified source code on the explorer.',
        'items' => [
            ['Cannot mint new tokens', 'The supply of 1.1 trillion is fixed forever. There is no mint function.'],
            ['Cannot halt transfers or blacklist wallets', 'No pause switch and no blacklist. Your tokens are yours.'],
            ['Cannot withdraw locked tokens early', 'The lock contract has no owner and releases tokens only on schedule.'],
            ['Cannot raise the presale price mid-round', 'The price is set when the contract is created. There is no function to change it.'],
            ['Cannot hold buyers’ payments in the contract', 'USDT goes straight to the project treasury, and you receive your tokens in the same transaction.'],
        ],
        'vault_alt' => 'a glass vault divided into four time periods',
        'vault_badge' => 'IDTC locked in the contract · fully unlocked within 60 months',
    ],

    'tokenomics' => [
        'title' => '1.1 trillion tokens: <span class="grad">70% locked</span>, 30% circulating',
        'chart' => 'Token allocation chart',
        'total' => 'Total IDTC',
        'col_part' => 'Allocation',
        'col_share' => 'Share',
        'col_amount' => 'Amount',
    ],

    'unlock' => [
        'kicker' => 'Unlock schedule',
        'title' => 'No single day with a <span class="grad">large unlock</span>',
        'lead' => 'Each tranche stays fully locked for its period, then unlocks in 12 equal monthly installments. Circulating supply therefore grows gradually, with at most 1.67% of supply unlocking in any month.',
        'legend_circ' => 'Cumulative circulating supply',
        'legend_locked' => 'Still locked',
        'month_zero' => 'Month 0 = launch day',
        'chart' => 'Circulating supply over 60 months',
        'notes' => [
            ['330 billion', 'circulating from day one (30%)'],
            ['18.33 billion / month', 'maximum monthly unlock (months 13–36)'],
            ['Month 60', 'all 1.1 trillion tokens unlocked'],
        ],
    ],

    'buy' => [
        'kicker' => 'How to buy',
        'title' => 'Three ways to buy IDTC <span class="grad">with USDT</span>',
        'bridge_alt' => 'a bridge of light connecting two networks',
        'pill_bsc' => 'USDT on BNB Chain',
        'pill_tpix' => 'IDTC on TPIX Chain',
        'best' => 'Fixed price, no slippage',
        'routes' => [
            ['title' => 'Fixed-price presale', 'text' => 'Pay USDT on TPIX Chain and receive IDTC instantly at $:price, with no claim period.', 'meta' => 'Suited to first-time buyers'],
            ['title' => 'Swap on TPIX DEX', 'text' => 'The IDTC/USDT and IDTC/TPIX pairs trade 24 hours a day; prices follow the liquidity in each pool.', 'meta' => 'Pool fee 0.3% · zero gas fee'],
            ['title' => 'Hold USDT on another chain', 'text' => 'Send USDT from BNB Chain through the TPIX bridge to receive USDT on TPIX Chain 1:1, then buy using option 1 or 2.', 'meta' => 'BNB Chain supported first; other chains to follow'],
        ],
    ],

    'roadmap' => [
        'title' => 'What is done <span class="grad">and what is in progress</span>',
        'phases' => [
            ['status' => '✓ Done', 'title' => 'Foundations', 'items' => ['Project and website launched (Apr 2026)', 'Own TPIX Chain with zero gas fees', 'TPIX DEX and Token Factory']],
            ['status' => '● In progress', 'title' => 'Launch on TPIX Chain', 'items' => ['Create IDTC through the Token Factory', '70% lock contract and USDT presale', 'IDTC/USDT and IDTC/TPIX pools']],
            ['status' => 'Next', 'title' => 'Wider access', 'items' => ['Automated USDT bridge from BNB Chain', 'Apply for CoinGecko and CoinMarketCap listings', 'wIDTC on BNB Chain and PancakeSwap']],
        ],
    ],

    'wp' => [
        'version' => 'Version 2.0 · 2026',
        'kicker' => 'Whitepaper, version 2',
        'title' => 'Rewritten from scratch: every figure <span class="grad">traces back to the contracts</span>',
        'toc' => ['Executive summary', 'The problem and the opportunity in energy', 'Why TPIX Chain', 'Tokenomics and allocation', 'Lock contract and unlock schedule', 'Presale and liquidity', 'Governance and use of funds', 'Roadmap', 'Risks and legal notice', 'All contract addresses'],
        'cta' => 'Read the whitepaper',
    ],

    'faq' => [
        'kicker' => 'FAQ',
        'title' => 'What to know before you buy',
        'items' => [
            ['Why did IDTC move from Polygon to TPIX Chain?', 'TPIX Chain charges no gas fees, so holders can buy, sell and transfer without keeping another coin in their wallet. The original presale contract on Polygon never had a single buyer, so the move affects no one.'],
            ['Who can withdraw the locked tokens?', 'No one can withdraw them early. The lock contract has no owner and releases tokens on schedule only to the designated wallet. Anyone can view the remaining balance and the next unlock on the Transparency page.'],
            ['Can the presale price change?', 'No. The rate of 1 USDT = :rate IDTC is fixed when the contract is created. The team can pause the sale, but cannot raise or lower the price.'],
            ['What do I need to buy?', 'An EVM-compatible wallet such as MetaMask, and USDT on TPIX Chain. If your USDT is on BNB Chain, bridge it first. You do not need TPIX to pay gas fees.'],
            ['How will the funds raised be used?', 'USDT from the presale goes directly to the project treasury. Part of it tops up pool liquidity; the rest is used according to the plan published in the whitepaper. Treasury movements can be checked on the explorer.'],
        ],
    ],

    'risk' => [
        'title' => 'Risk warning',
        'text' => 'IDTC is a digital asset. It is not a deposit, and no return is guaranteed. Its price may be extremely volatile or fall until it is worthless. Invest only what you can afford to lose, and study the laws of your own country first. In Thailand, public offerings of digital tokens are governed by the Emergency Decree on Digital Asset Businesses B.E. 2561 (2018).',
    ],

    'cta' => [
        'title' => 'Start at <span class="grad">$:price</span> per token',
        'text' => 'One price for everyone throughout the presale, with tokens delivered instantly in a single transaction.',
        'presale' => 'Go to the presale',
    ],
];
