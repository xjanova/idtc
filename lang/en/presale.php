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
        'treasury' => 'Proceeds go to treasury',
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
            'The contract never holds buyers’ funds; USDT goes straight to the project treasury',
            'The team can withdraw unsold tokens only after the round closes or while the sale is paused; tokens already bought are unaffected',
        ],
        'note' => 'The team can pause the sale and adjust the minimum, the per-wallet limit and the sale window. Every such action is recorded on-chain and can be audited.',
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
];
