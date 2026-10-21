<?php

// /bridge page — deposit/withdraw USDT between BNB Chain and TPIX Chain · Thai counterpart lang/th/bridge.php

return [
    'title' => 'Deposit and withdraw USDT',
    'description' => 'Bring USDT from BNB Chain to TPIX Chain 1:1 to buy IDTC, and withdraw back to BNB Chain, through the TPIX USDT bridge.',
    'h1' => 'Bring USDT from <span class="grad nowrap">BNB Chain</span>',
    'lead' => 'Send USDT (BEP-20) from your wallet and receive USDT on TPIX Chain at the same address, 1:1, within minutes. Withdraw back whenever you like.',

    'aria' => 'Deposit and withdraw USDT',
    'tab_deposit' => 'Deposit (BSC → TPIX)',
    'tab_withdraw' => 'Withdraw (TPIX → BSC)',

    'loading' => 'Checking the bridge…',
    'open' => 'Bridge open',
    'closed' => 'Bridge closed',

    'dep' => [
        'from' => 'Send from BNB Chain',
        'to' => 'Receive on TPIX Chain (same address)',
        'in_wallet' => 'USDT on BSC',
        'max' => 'Max',
        'aria' => 'Amount of USDT to send',
        'button' => 'Send USDT from BSC',
        'connect' => 'Connect wallet',
        'hint' => 'Your wallet switches to BNB Chain and asks you to confirm a USDT transfer to the bridge deposit address (keep a little BNB for gas).',
    ],
    'wd' => [
        'from' => 'Withdraw from TPIX Chain',
        'to' => 'Receive on BNB Chain',
        'in_wallet' => 'USDT on TPIX Chain',
        'max' => 'Max',
        'aria' => 'Amount of USDT to withdraw',
        'target' => 'Receiving address on BNB Chain',
        'target_self' => 'Your own address',
        'target_other' => 'A different address. Double-check it: funds sent to a wrong address cannot be recovered.',
        'button' => 'Withdraw to BNB Chain',
        'hint' => 'Your wallet switches to TPIX Chain and asks you to confirm the withdrawal (gas 0).',
    ],

    'kv' => [
        'min' => 'Minimum per transfer',
        'max' => 'Maximum per transfer',
        'daily' => 'Per address per 24 h',
        'today' => 'Bridge quota left today',
        'fee' => 'Fee',
        'eta' => 'Estimated time',
        'minutes' => ':n min',
        'free' => 'None',
        'gas_bsc' => 'BNB for gas',
        'receive' => 'You receive',
    ],

    'sender_contract' => 'This address is a contract (for example a multisig). The bridge will not mint for it automatically. Use a personal wallet instead.',
    'no_bnb' => 'Your wallet has no BNB on BNB Chain for gas. Add a little BNB first (about 0.0005 BNB is enough).',

    'warn' => [
        'title' => 'Read before depositing',
        'items' => [
            'Send only from your own wallet (MetaMask, Trust, OKX, etc.). Never withdraw from an exchange straight to the bridge address: the USDT would be credited to the exchange address.',
            'Use USDT (BEP-20) on BNB Chain only. Other tokens or chains are not counted.',
            'Amounts below the minimum are not counted and are not refunded automatically. Amounts above the limits wait for a manual review.',
            'Up to 6 decimal places, matching USDT on TPIX Chain.',
            'The bridge is operated by TPIX (tpix.online), not by an IDTC contract.',
        ],
    ],

    'track' => [
        'title' => 'Your transfers',
        'empty' => 'No transfers yet. Transfers sent from this page show their status here.',
        'lookup' => 'Check by transaction hash',
        'lookup_ph' => '0x…',
        'as_deposit' => 'As a deposit',
        'as_withdraw' => 'As a withdrawal',
        'deposit' => 'Deposit',
        'withdraw' => 'Withdrawal',
        'forget' => 'Hide',
        'note' => 'This list is kept in this browser only.',
    ],

    'next' => [
        'title' => 'Got USDT on TPIX Chain',
        'text' => 'Use it to buy IDTC at the fixed price on the Presale page, or swap with the pool on the Trade page.',
        'presale' => 'Go to the Presale',
        'trade' => 'Go to Trade',
        'add_usdt' => 'Add USDT (TPIX) to wallet',
    ],

    'official' => 'Main bridge page at tpix.online',
];
