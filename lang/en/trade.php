<?php

// /trade page — trade IDTC on TPIX DEX · Thai counterpart lang/th/trade.php (keys must match)

return [
    'title' => 'Trade IDTC',
    'description' => 'Buy and sell IDTC against USDT or TPIX on TPIX DEX at any time. Prices come from the live pool. Gas is 0.',
    'h1' => 'Buy and sell IDTC <span class="grad nowrap">any time</span>',
    'lead' => 'Swap with the pool on TPIX DEX straight from your wallet. The price moves with the pool balances. Pool fee 0.3%, gas 0.',

    'aria' => 'Trade IDTC',
    'buy' => 'Buy',
    'sell' => 'Sell',
    'with' => 'Pair with',
    'pay' => 'You pay',
    'receive' => 'You receive (estimate)',
    'in_wallet' => 'In wallet',
    'flip' => 'Switch buy/sell',
    'pct' => 'Share of your wallet balance',

    'status' => [
        'nodeploy' => 'Awaiting deployment',
        'loading' => 'Reading the pool…',
        'error' => 'Cannot read the pool',
        'nopool' => 'Pool not open yet',
        'live' => 'Trading open',
    ],
    'pending' => 'The IDTC token is not on chain yet (awaiting deployment). Trading opens here once the token and its TPIX DEX pools exist. Meanwhile you can buy at the fixed price on the Presale page once it opens.',
    'no_pool_note' => 'This pool is not open yet. The team will add liquidity as the whitepaper describes. Try the other pair, or buy on the Presale page.',

    'kv' => [
        'price' => 'Pool price',
        'min_out' => 'Minimum received (after slippage)',
        'impact' => 'Price impact',
        'fee' => 'Pool fee',
        'gas' => 'Gas',
        'slippage' => 'Slippage tolerance',
        'pool' => 'Pool contract',
    ],

    'settings' => 'Settings',
    'slippage_title' => 'Slippage tolerance',
    'slippage_help' => 'If the price moves more than this while you confirm, the swap cancels itself and you keep your tokens.',
    'slippage_custom' => 'Custom (%)',

    'impact_warn' => 'This amount moves the price a lot because the pool is still small. Consider splitting it into several trades.',
    'impact_high' => 'Very high price impact: you would receive far less than the pool price.',
    'impact_ack' => 'I understand and accept this price impact',
    'presale_better' => 'The Presale gives :out IDTC for the same amount right now (more than the pool).',
    'presale_go' => 'Go to the Presale →',

    'connect' => 'Connect wallet to trade',
    'connecting' => 'Connecting wallet…',
    'do_buy' => 'Buy IDTC',
    'do_sell' => 'Sell IDTC',
    'approve_then' => '(approve the token first, then confirm the swap)',
    'working' => 'Working…',
    'hint_chain' => 'Your wallet is on another chain. When you press the button we will ask it to switch to TPIX Chain.',
    'hint_approve' => 'The first time, your wallet asks twice: approve the exact amount, then confirm the swap.',
    'no_usdt' => 'No USDT on TPIX Chain yet? Bring it from BNB Chain on the page',
    'no_usdt_link' => 'Deposit USDT',
    'open_in_wallet' => 'Open this site in a wallet app →',

    'success_buy' => 'Purchase complete',
    'success_sell' => 'Sale complete',
    'success_got' => 'Received',
    'success_est' => '(estimate)',
    'add_idtc' => 'Add IDTC to wallet',
    'again' => 'New trade',

    'wallet' => [
        'title' => 'Your wallet',
        'idtc' => 'IDTC',
        'usdt' => 'USDT on TPIX Chain',
        'tpix' => 'TPIX',
        'refresh' => 'Refresh balances',
    ],

    'pool' => [
        'title' => 'Pools on TPIX DEX',
        'reserves' => 'Pool balances',
        'rate' => 'Rate',
        'none' => 'Not open',
        'note' => 'The pool price comes only from the two token balances: buying pushes it up, selling pushes it down. The team does not set it.',
    ],

    'risk' => [
        'title' => 'Before you trade',
        'items' => [
            'Pool prices move all the time, and the early pools are small, so large amounts move the price a lot.',
            'Always check the token contract address. Anyone can create a fake token named IDTC. The real addresses are on the Transparency page.',
            'IDTC lives on TPIX Chain only. You cannot send it directly to another chain.',
            'This is not investment advice. Digital assets carry risk.',
        ],
    ],
];
