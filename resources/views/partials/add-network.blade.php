@php
    // ลิงก์เปิดเว็บในแอปกระเป๋า: หน้าเพิ่มเชนในภาษาเดียวกับหน้าที่เปิดอยู่ (/add-network หรือ /en/add-network)
    $site = preg_replace('#^https?://#', '', rtrim(lroute('add-network'), '/'));
    $enc = rawurlencode('https://'.$site);
    $wallets = [
        ['MetaMask', 'M', '#f6851b', 'https://metamask.app.link/dapp/'.$site],
        ['Trust Wallet', 'T', '#3375bb', 'https://link.trustwallet.com/open_url?coin_id=60&url='.$enc],
        ['OKX Wallet', 'O', '#1f2937', 'https://www.okx.com/download?deeplink='.rawurlencode('okx://wallet/dapp/url?dappUrl='.$enc)],
        ['Bitget Wallet', 'B', '#00b4c8', 'https://bkcode.vip?action=dapp&url='.$enc],
        ['Coinbase Wallet', 'C', '#0052ff', 'https://go.cb-w.com/dapp?cb_url='.$enc],
        ['TokenPocket', 'TP', '#2980fe', 'tpoutside://pull.activity?param='.rawurlencode(json_encode(['url' => 'https://'.$site]))],
    ];
@endphp
<div class="addnet reveal" id="addnet">
    <div>
        <h3>{{ __('addnet.desktop_title') }}</h3>
        <p>{{ __('addnet.desktop_text') }}</p>
        <dl class="netinfo">
            <dt>{{ __('addnet.network') }}</dt><dd>{{ config('idtc.chain.name') }}</dd>
            <dt>Chain ID</dt><dd>{{ config('idtc.chain.id') }} (0x{{ strtoupper(substr((string) config('idtc.chain.hex'), 2)) }})</dd>
            <dt>RPC</dt><dd>{{ config('idtc.chain.rpc_public') }}</dd>
            <dt>Explorer</dt><dd>{{ config('idtc.chain.explorer') }}</dd>
            <dt>{{ __('addnet.currency') }}</dt><dd>{{ __('addnet.currency_value') }}</dd>
            <dt>{{ __('addnet.token') }}</dt><dd>{{ \App\Support\Idtc::contract('token') ?? __('site.pending') }}</dd>
        </dl>
        <button class="btn btn-primary" type="button" @click="addNetwork()"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>{{ __('site.add_network_full') }}</button>
    </div>
    <div>
        <h3>{{ __('addnet.mobile_title') }}</h3>
        <p>{{ __('addnet.mobile_text') }}</p>
        <div class="wallets">
            @foreach ($wallets as [$name, $letter, $color, $href])
                <a class="wallet" href="{{ $href }}" rel="noopener"><span class="wl" style="background:{{ $color }}">{{ $letter }}</span>{{ $name }}</a>
            @endforeach
        </div>
    </div>
</div>
