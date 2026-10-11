@php
    $site = preg_replace('#^https?://#', '', rtrim(url('/add-network'), '/'));
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
        <h3>เพิ่มเชน TPIX และเหรียญ IDTC ในคลิกเดียว</h3>
        <p>บนคอมพิวเตอร์ ปุ่มนี้ให้กระเป๋า (MetaMask, OKX, Rabby ฯลฯ) เพิ่มเชนก่อน แล้วเพิ่มเหรียญ IDTC ต่อให้ทันที</p>
        <dl class="netinfo">
            <dt>ชื่อเครือข่าย</dt><dd>{{ config('idtc.chain.name') }}</dd>
            <dt>Chain ID</dt><dd>{{ config('idtc.chain.id') }} ({{ strtoupper(str_replace('0x', '0x', config('idtc.chain.hex'))) }})</dd>
            <dt>RPC</dt><dd>{{ config('idtc.chain.rpc_public') }}</dd>
            <dt>Explorer</dt><dd>{{ config('idtc.chain.explorer') }}</dd>
            <dt>สกุลเงิน</dt><dd>TPIX · 18 ทศนิยม</dd>
            <dt>เหรียญ IDTC</dt><dd>{{ \App\Support\Idtc::contract('token') ?? 'รอ deploy' }}</dd>
        </dl>
        <button class="btn btn-primary" type="button" @click="addNetwork()"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>เพิ่มเชน + เหรียญ IDTC</button>
    </div>
    <div>
        <h3>บนมือถือ: เปิดในแอปกระเป๋า</h3>
        <p>แตะแอปที่คุณใช้ เว็บนี้จะเปิดในเบราว์เซอร์ของแอปนั้น แล้วเพิ่มเชนกับเหรียญให้อัตโนมัติ</p>
        <div class="wallets">
            @foreach ($wallets as [$name, $letter, $color, $href])
                <a class="wallet" href="{{ $href }}" rel="noopener"><span class="wl" style="background:{{ $color }}">{{ $letter }}</span>{{ $name }}</a>
            @endforeach
        </div>
    </div>
</div>
