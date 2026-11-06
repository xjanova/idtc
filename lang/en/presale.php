<?php

return [
    'title' => 'Presale',
    'description' => 'Buy IDTC at a fixed $:price with USDT on TPIX Chain. Tokens are delivered instantly in a single transaction, with zero gas fees.',
    'h1' => 'Buy IDTC <span class="nowrap">at a fixed price</span> <span class="grad nowrap">with USDT</span>',
    'lead' => 'Tokens arrive instantly in a single transaction, with no claim period · $:price per IDTC, the same for everyone · zero gas fees on TPIX Chain',

    'buy_aria' => 'Buy IDTC',
    'reading' => 'Reading contract…',
    'plan_note' => 'Figures use the planned price',
    'pending' => 'The presale contract is not on-chain yet (awaiting deployment). The buy button opens once the contract is ready. The price below is the same price that will be fixed in the contract.',
    'rate_mismatch' => 'The rate in the contract differs from the published plan. This page calculates with the contract’s rate.',
    'token_mismatch' => 'This contract sells a different token from the announced IDTC. Do not buy yet; check the contract addresses on the Transparency page first.',
    'pay_mismatch' => 'This contract accepts a different payment token from the configured USDT. This page uses the token the contract specifies.',

    'you_pay' => 'You pay',
    'in_wallet' => 'In wallet',
    'pay_aria' => 'USDT amount to pay',
    'quick' => 'Quick amounts',
    'max' => 'Max',
    'you_get' => 'You receive',
    'on_confirm' => 'as soon as the chain confirms',
    'quote_mismatch' => 'The contract’s figure differs from this page’s calculation; the contract’s figure is shown.',

    'kv' => [
        'price' => 'Price',
        'gas' => 'Gas fee',
        'min' => 'Minimum per purchase',
        'per_wallet' => 'Per-wallet limit',
        'unlimited' => 'Unlimited',
        'treasury' => 'Proceeds go to the pool feeder',
    ],

    'connecting' => 'Connecting wallet…',
    'connect' => 'Connect wallet to buy',
    'approving' => 'Approving…',
    'approved' => '✓ USDT approved',
    'approve' => '1 · Approve USDT',
    'buying' => 'Buying…',
    'buy' => '2 · Buy IDTC',
    'hint_chain' => 'Your wallet is on another chain. When you buy, it will be asked to switch to TPIX Chain.',
    'hint_approve' => 'One tap: approve exactly the USDT you are spending, then your wallet asks you to confirm the purchase right away.',
    'hint_status' => 'You can buy when the status is “Live”.',
    'hint_pending' => 'The buy button activates once the presale contract is on-chain. Meanwhile, you can connect your wallet to see your USDT balance on TPIX Chain.',
    'open_in_wallet' => 'Open this site in your wallet app →',

    'success' => 'Purchase complete. The tokens are in your wallet.',
    'success_got' => 'Received',
    'success_for' => 'IDTC for',
    'add_idtc' => 'Add IDTC to wallet',
    'again' => 'Buy more',

    'sold' => 'Sold',
    'sold_aria' => 'Share sold',
    'left' => 'remaining',
    'cap_total' => 'Total cap',
    'cant_read' => 'Cannot read totals from the contract yet',
    'reading_totals' => 'Reading totals from the contract…',
    'waiting' => 'Awaiting the presale contract',

    'wallet' => [
        'title' => 'Your wallet',
        'usdt' => 'USDT on TPIX Chain',
        'allowance' => 'Approved for the presale',
        'bought' => 'Bought in the presale',
        'left' => 'Still available (per-wallet limit)',
        'idtc' => 'IDTC in wallet',
        'pending' => 'The presale contract is not on-chain yet. The balance above is your USDT on TPIX Chain.',
        'refresh' => 'Refresh balances',
        'add_usdt' => 'Add USDT to wallet',
    ],

    'rules' => [
        'title' => 'Rules enforced by the contract',
        'items' => [
            'Fixed price for the whole round, 1 USDT = :rate IDTC, with no function to change it',
            'Total sales capped at :cap IDTC',
            'Tokens arrive in the same transaction as the payment, with no claim step',
            'USDT goes straight to the pool feeder, which adds :share% of it to the IDTC/USDT pool',
            'The pool LP is locked in the contract until :lp_unlock; nobody can withdraw it earlier, the team included',
            'Unsold tokens can be withdrawn only after :lp_unlock; tokens already bought are unaffected',
            'Where the proceeds go cannot be changed: the pool feeder is the presale contract’s permanent owner',
        ],
        'note' => 'The team can pause the sale, adjust the minimum and the per-wallet limit, and move or shorten the sale window, but cannot extend it past :end. Every such action is recorded on-chain and can be audited.',
    ],

    'bridge' => [
        'title' => 'No USDT on TPIX Chain yet?',
        'text' => 'Send USDT from BNB Chain through the TPIX bridge to receive USDT on TPIX Chain at 1:1, then come back to buy on this page.',
        'cta' => 'Go to the USDT bridge (tpix.online)',
    ],

    'contracts' => [
        'title' => 'Contract addresses',
        'check' => 'Check the source code and every transaction at :explorer · see everything on the :transparency page',
    ],

    'schedule' => [
        'title' => 'Schedule',
        'open' => 'Sale opens',
        'close' => 'Sale closes',
        'length' => 'Length',
        'days' => ':n days',
        'lp_unlock' => 'Pool LP unlocks',
        'vesting' => '70% lock starts counting',
        'vesting_same' => 'On the opening day',
        'note' => 'Planned dates as announced. Once the contract is on-chain, the buy button and the countdown follow the contract’s own times only.',
    ],

    'ways' => [
        'title' => 'Two ways to buy, one price',
        'bsc' => [
            'title' => 'Pay with USDT on BNB Chain',
            'text' => 'Buy on TPIX Launchpad at tpix.online: send USDT on BNB Chain once and the system delivers IDTC to your wallet on TPIX Chain. No bridging on your side.',
            'cta' => 'Buy on tpix.online',
            'note' => 'The buy page opens on the opening day',
        ],
        'tpix' => [
            'title' => 'Pay with USDT on TPIX Chain',
            'text' => 'Buy on this page and receive the tokens in a single transaction. No USDT on TPIX Chain yet? Send it from BNB Chain through the TPIX bridge first.',
            'cta' => 'USDT bridge',
        ],
        'same' => 'Both ways buy from the same presale contract, at the same price and cap, and the proceeds go to the pool the same way.',
    ],

    'release' => [
        'title' => 'Token release conditions',
        'lead' => 'The supply is fixed at :total IDTC with no minting. Each part reaches the market only under the conditions the contracts enforce:',
        'groups' => [
            'presale' => ['title' => 'Presale :amount', 'text' => 'Buyers receive their tokens in the paying transaction, with no lock. Unsold tokens stay in the presale contract and can be withdrawn only after :lp_unlock.'],
            'pool' => ['title' => 'Paired into the pool :amount', 'text' => 'Held by the pool feeder, which can use them for one thing only: pairing with sale USDT in the IDTC/USDT pool. Anything unused can be withdrawn only after :lp_unlock.'],
            'locked' => ['title' => '70% lock :amount', 'text' => 'Held by an ownerless lock contract in 4 tranches. Each tranche stays fully locked first, then releases in 12 equal monthly steps. Anyone can trigger a due release, but tokens go only to the fixed beneficiary wallet.'],
            'free' => ['title' => 'Project treasury :amount', 'text' => 'Operations, marketing, listings and reserve. Not locked in a contract and usable from day one; anyone can follow this wallet’s balance and transfers on the explorer.'],
        ],
        'table' => [
            'caption' => 'Release schedule of the 70% lock (counted from the opening day, :start)',
            'tranche' => 'Tranche',
            'amount' => 'Amount (IDTC)',
            'first' => 'First release',
            'last' => 'Fully released',
            'monthly' => 'Per release',
            'total' => 'Total',
        ],
        'table_note' => 'Dates are computed from the plan (each step is 2,629,800 seconds, an average month). The real dates can be read from the contract on the :transparency page.',
        'lp' => [
            'title' => 'Where the money goes',
            'text' => 'Every USDT paid goes straight to the pool feeder contract, which adds :share% of it to the IDTC/USDT pool together with IDTC at the sale price. The LP tokens stay locked in the contract until :lp_unlock; nobody can withdraw them earlier, the team included.',
            'band' => 'The feeder adds liquidity only while the pool price is within :band% of the sale price. If the pool price drifts outside, the USDT waits in the feeder until the price comes back, and it cannot leave before :lp_unlock.',
            'band_exact' => 'The feeder adds liquidity only while the pool price equals the sale price. If the pool price drifts, the USDT waits in the feeder until the price is back, and it cannot leave before :lp_unlock.',
        ],
    ],

    'docs' => [
        'title' => 'Documents and verification links',
        'whitepaper' => 'Whitepaper',
        'transparency' => 'Transparency page (reads the contracts live)',
        'launchpad' => 'IDTC sale page on TPIX Launchpad',
        'source' => 'Contract source code (GitHub)',
        'explorer' => 'TPIX Explorer',
        'add_network' => 'How to add TPIX Chain to your wallet',
    ],
];
