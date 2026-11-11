<?php

// /rewards — public reward plan page (no wallet, no sign-in) · keys must match lang/th/rewards.php
// Every number is passed in from the active plan (config/idtc.php grid.plan) or the sale config — never typed here.

return [
    'title' => 'IDTC Grid reward plan — every number and every rule',
    'description' => 'How IDTC Grid pays referral rewards: a fixed budget of :budget IDTC, three rings at :r1% / :r2% / :r3%, a :hold-day hold rule and on-chain payouts you claim yourself. Read it without connecting a wallet.',
    'kicker' => 'IDTC Grid · Reward plan',
    'h1' => 'A referral plan with a <span class="grad">fixed budget</span>, clear rules and on-chain payouts',
    'lead' => 'Share your link. When friends buy IDTC in the presale through it, you receive IDTC from a budget set aside before the sale began — never from buyers\' money. This page shows the whole plan. No wallet or sign-in needed to read it.',

    'cta_open' => 'Get my invite link',
    'cta_soon' => 'Sign-up for IDTC Grid has not opened yet',
    'cta_presale' => 'See the presale',
    'cta_video' => 'Watch the video (4:25)',

    'stats' => [
        'budget' => 'Reward budget',
        'rings' => 'Rings 1 · 2 · 3',
        'buyer' => 'Extra for the buyer',
        'hold' => 'Hold before it counts',
        'days' => 'days',
    ],

    'video' => [
        'kicker' => 'Video',
        'title' => 'The whole plan in <span class="grad">one video</span>',
        'lead' => 'English narration with Thai subtitles. Every figure in the video comes from the same plan as this page.',
        'caption' => 'IDTC Grid — the reward plan · English narration, Thai subtitles',
        'track' => 'English',
        'play' => 'Play the IDTC Grid reward plan video',
        'watch' => 'Watch · 4:25',
    ],

    'steps' => [
        'kicker' => 'How to join',
        'title' => 'Four steps, <span class="grad">free to join</span>',
        'items' => [
            ['Connect your wallet', 'Any EVM wallet on TPIX Chain. No account, no password.'],
            ['Sign one message', 'Proves the wallet is yours. Costs no gas and moves no tokens.'],
            ['Share your link', 'Your own invite link appears right away. A friend binds to you before their first purchase.'],
            ['Receive IDTC', 'When a friend buys and holds for :hold days, the reward counts toward your next payout round.'],
        ],
    ],

    'rings' => [
        'kicker' => 'Three rings',
        'title' => 'Rewards counted <span class="grad">up from the buyer</span>',
        'lead' => 'Each ring is a share of the IDTC that friend bought. Ranks unlock the rings one by one.',
        'you' => 'YOU',
        'ring' => 'Ring :n',
        'ring1' => 'Friends you invited yourself',
        'ring2' => 'Friends of those friends',
        'ring3' => 'One level further',
        'buyer' => 'The friend who bought through a link gets an extra',
        'boost_title' => 'Launch bonus on ring 1',
        'boost_lead' => 'Ring 1 earns more in the first weeks of the sale. The week of each purchase decides its rate.',
        'week_range' => 'Sale weeks :from–:to',
        'week_after' => 'From sale week :from',
        'dates' => ':from – :to',
        'dates_from' => 'from :from',
    ],

    'calc' => [
        'kicker' => 'Try it',
        'title' => 'What one purchase <span class="grad">pays out</span>',
        'lead' => 'Enter what a friend buys. The calculator uses the presale rate and the plan rates shown on this page.',
        'usdt' => 'Friend buys (USDT)',
        'week' => 'Bought in',
        'bought' => 'Friend receives from the sale',
        'col_who' => 'Who',
        'col_rate' => 'Rate',
        'col_idtc' => 'IDTC',
        'if_ring' => 'You, if this friend is in your ring :n',
        'buyer' => 'The friend (buyer bonus)',
        'rate_note' => 'Presale rate: 1 USDT = :rate IDTC.',
        'note' => 'These are token amounts, not cash. What they are worth depends on the market price of IDTC, which can fall. A reward counts only after the buyer holds for :hold days, and only if your rank has unlocked that ring.',
    ],

    'ranks' => [
        'kicker' => 'Ranks',
        'title' => 'Ranks unlock the rings <span class="grad">step by step</span>',
        'lead' => 'Team volume is the USDT bought in the presale by everyone below you in the team. Direct friends count once they have bought at least :own USDT themselves.',
        'need' => 'Requirement',
        'get' => 'Unlocks',
    ],

    'sprint' => [
        'kicker' => 'Weekly Sprint',
        'title' => 'A weekly pool <span class="grad">shared by team volume</span>',
        'pool' => 'of each week\'s IDTC sold goes into the Sprint pool',
        'who' => 'Shared among Grid and Core ranks by that week\'s team volume. Core counts double.',
        'cap' => 'is the most one wallet can take from a single pool',
        'rest' => 'Whatever is not taken stays in the budget.',
    ],

    'fair' => [
        'kicker' => 'Fair by design',
        'title' => 'Rules that <span class="grad">stop overpaying</span>',
        'items' => [
            'budget' => ['Fixed budget', ':budget IDTC set aside from the operations and marketing allocation (:share% of :alloc). The plan cannot be changed so that its worst case exceeds it.'],
            'hold' => ['Hold :hold days', 'A purchase counts only after the buyer holds the tokens for :hold days. Anything sold or moved out early earns nothing, and that share returns to the budget.'],
            'chain' => ['Checked on-chain', 'Holding is checked from on-chain transfers, not from anyone\'s word.'],
            'nocomp' => ['No pass-up', 'An empty ring, or an upline who has not reached the rank, does not pass the share further up. It returns to the budget.'],
            'bind' => ['Bind once', 'An inviter is bound only once, before the first purchase. You cannot invite yourself.'],
            'rate' => ['No retroactive changes', 'Each purchase uses the rates of the week it was made. A changed plan applies from the following week, never backwards.'],
        ],
    ],

    'money' => [
        'kicker' => 'Where the money goes',
        'title' => 'Rewards never come from <span class="grad">buyers\' money</span>',
        'sale' => 'Presale USDT',
        'pool' => 'goes into the IDTC/USDT liquidity pool',
        'lock' => 'Liquidity locked until',
        'budget' => 'Rewards come from',
        'budget_of' => 'a separate budget of :budget IDTC',
        'link' => 'See every address on the transparency page',
    ],

    'payout' => [
        'kicker' => 'Payout',
        'title' => 'Paid on-chain, <span class="grad">claimed by you</span>',
        'lead' => 'The team publishes payout rounds to a reward contract on TPIX Chain. You claim each round straight to your own wallet, with zero gas.',
        'instant' => 'Claimable as soon as a round is published',
        'later' => 'Unlocks after the presale closes',
        'later_from' => 'from :date',
        'keys' => 'The website never holds your keys. Every claim is signed by you, in your own wallet.',
    ],

    'faq' => [
        'kicker' => 'Questions',
        'title' => 'Before you share your link',
        'items' => [
            ['Do I have to buy IDTC to get a link?', 'No. Joining is free and every wallet gets its own link after signing one message. To receive ring 1 rewards you need the Spark rank, which means buying at least :own USDT yourself.'],
            ['Is this an investment that pays a return?', 'No. Rewards are IDTC tokens paid from a marketing budget for bringing buyers to the presale. They are not interest, dividends or a promised return, and IDTC can lose value.'],
            ['Where do the reward tokens come from?', 'From a budget of :budget IDTC set aside from the project\'s operations and marketing allocation. The USDT that buyers pay goes into the liquidity pool, not to rewards.'],
            ['What if the budget runs out?', 'Rewards stop at the budget. The plan is checked so that even its worst case cannot exceed it, and no one is paid beyond it.'],
            ['Why the :hold-day hold?', 'So that rewards go to real buyers who keep the tokens. A purchase that is sold or moved out before :hold days earns no reward for anyone.'],
            ['When can I claim?', 'When the team publishes a payout round on-chain. :instant% of each round can be claimed at once; the rest unlocks after the presale closes on :end.'],
            ['Can the plan change?', 'The project can adjust or stop the plan. A change applies from the following sale week and never changes rewards already earned.'],
        ],
    ],
];
