<?php

// Shared public-site strings (layout, menus, footer) — Thai counterpart: lang/th/site.php (same keys)

return [
    'name' => 'IDTC Energy Capital',
    'title' => 'IDTC Energy Capital — an energy-development token on TPIX Chain',
    'description' => 'IDTC is an energy-development token on TPIX Chain. 70% of the supply is locked in an ownerless contract, the presale price of $:price is fixed in the contract, payment is in USDT and gas fees are zero.',

    'og_image' => 'images/brand/og-en.jpg',

    'nav' => [
        'home' => 'Overview',
        'presale' => 'Presale',
        'rewards' => 'Rewards',
        'transparency' => 'Transparency',
        'whitepaper' => 'Whitepaper',
        'grid' => 'Invite',
        'binary' => 'Binary plan',
        'add-network' => 'Add network',
        'main' => 'Main menu',
        'mobile' => 'Mobile menu',
        'menu' => 'Menu',
    ],

    'add_network' => 'Add chain + token',
    'add_network_full' => 'Add chain + IDTC token',
    'connect' => 'Connect wallet',

    'lang' => [
        'label' => 'Choose language',
        'th' => 'Thai',
        'en' => 'English',
    ],

    'hint' => [
        'text' => 'This page is in Thai.',
        'switch' => 'Switch to English?',
        'dismiss' => 'Dismiss',
    ],

    'footer' => [
        'tagline' => 'An energy-development token on TPIX Chain, 70% locked and verifiable on-chain.',
        'token' => 'Token',
        'docs' => 'Documents',
        'chain' => 'TPIX Chain',
        'faq' => 'FAQ',
        'how_to_buy' => 'How to buy',
        'disclaimer' => 'Not investment advice · Digital assets carry risk',
    ],

    'illus' => 'Illustration',
    'illus_alt' => 'Illustration: :what',
    'pending' => 'Awaiting deployment',
    'explorer_link' => 'View on explorer ↗',
    'explorer_tx' => 'View transaction on explorer ↗',
    'view_tx' => 'View transaction ↗',
    'retry' => 'Retry',
    'cancel' => 'Cancel',

    // Large token amounts: English counts in billions (10^9)
    'big' => [
        'exp' => '9',
        'unit' => 'billion',
    ],

    // Invite-link QR box (partials/qr-modal) — used on /grid, /binary and the admin
    'qr' => [
        'button' => 'QR',
        'title' => 'Invite link QR code',
        'alt' => 'QR code of the invite link',
        'link' => 'Invite link',
        'download' => 'Download image',
        'copy' => 'Copy link',
        'copied' => 'Copied',
        'close' => 'Close',
        'failed' => 'Could not draw the QR code — use the link below instead',
        'note' => 'Let your friend scan it with the phone camera, then open the link in their wallet browser.',
    ],
];
