<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminContext;
use App\Services\Admin\Audit;
use App\Services\Chain\Address;
use App\Services\Chain\BalanceReader;
use App\Services\Chain\PresaleReader;
use App\Services\Chain\VestingReader;
use App\Support\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * กระเป๋าโครงการ: รายชื่อที่แอดมินตั้ง + กระเป๋าที่อ่านจากสัญญา (รับเงิน presale, ผู้รับเหรียญปลดล็อก, เจ้าของ presale)
 */
class WalletController extends Controller
{
    public const MAX = 20;

    public function index(Settings $settings, BalanceReader $balances, PresaleReader $presale, VestingReader $vesting): View
    {
        $p = $presale->read();
        $vst = $vesting->read();

        $rows = [];
        foreach ($settings->projectWallets() as $w) {
            $rows[] = ['label' => $w['label'], 'address' => $w['address'], 'source' => 'ตั้งจากหลังบ้าน'];
        }
        $fromChain = [
            ['กระเป๋ารับเงิน presale (treasury)', $p['v']['treasury'] ?? null],
            ['เจ้าของสัญญา presale (owner)', $p['v']['owner'] ?? null],
            ['ผู้รับเหรียญที่ปลดล็อก (beneficiary)', $vst['v']['beneficiary'] ?? null],
        ];
        foreach ($fromChain as [$label, $address]) {
            if (Address::isValid($address) && ! Address::isZero($address)) {
                $rows[] = ['label' => $label, 'address' => Address::checksum($address), 'source' => 'อ่านจากสัญญา'];
            }
        }

        return view('admin.wallets', [
            'title' => 'กระเป๋าโครงการ',
            'rows' => $rows,
            'configured' => $settings->projectWallets(),
            'bal' => $balances->read(array_column($rows, 'address')),
            'max' => self::MAX,
        ]);
    }

    public function update(Request $request, Settings $settings): RedirectResponse
    {
        $labels = (array) $request->input('label', []);
        $addresses = (array) $request->input('address', []);
        $list = [];
        $seen = [];
        foreach ($addresses as $i => $address) {
            $address = trim((string) $address);
            $label = trim((string) ($labels[$i] ?? ''));
            if ($address === '' && $label === '') {
                continue;
            }
            $n = count($list) + 1;
            if (! Address::isValid($address) || ! Address::hasValidChecksum($address) || Address::isZero($address)) {
                return back()->withInput()->with('error', "แถวที่ {$n}: ที่อยู่กระเป๋าไม่ถูกต้อง (หรือ checksum ไม่ตรง)");
            }
            if ($label === '' || mb_strlen($label) > 60) {
                return back()->withInput()->with('error', "แถวที่ {$n}: ใส่ชื่อเรียกกระเป๋า (ไม่เกิน 60 ตัวอักษร)");
            }
            if (isset($seen[strtolower($address)])) {
                return back()->withInput()->with('error', "แถวที่ {$n}: ที่อยู่นี้ซ้ำกับแถวก่อนหน้า");
            }
            $seen[strtolower($address)] = true;
            $list[] = ['label' => $label, 'address' => Address::checksum($address)];
        }
        if (count($list) > self::MAX) {
            return back()->withInput()->with('error', 'ใส่ได้ไม่เกิน '.self::MAX.' กระเป๋า');
        }

        [$old] = $settings->put('project_wallets', $list, app(AdminContext::class)->address);
        Audit::change('wallets.update', $old ?: [], $list);

        return back()->with('status', 'บันทึกรายชื่อกระเป๋าโครงการแล้ว');
    }
}
