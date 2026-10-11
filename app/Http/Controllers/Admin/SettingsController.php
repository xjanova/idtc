<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminContext;
use App\Services\Admin\Audit;
use App\Services\Chain\Address;
use App\Services\Chain\ChainStatus;
use App\Services\Chain\ContractOrigin;
use App\Services\Chain\PoolReader;
use App\Services\Chain\PresaleReader;
use App\Services\Chain\RpcClient;
use App\Services\Chain\RpcException;
use App\Services\Chain\SalesIndexer;
use App\Services\Chain\VestingReader;
use App\Support\Idtc;
use App\Support\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ตั้งค่าเหรียญ: ที่อยู่สัญญา (แก้ทับ config/idtc.php) และบล็อกเริ่มดึงยอดขาย
 */
class SettingsController extends Controller
{
    public const LABELS = [
        'token' => ['เหรียญ IDTC (ERC-20)', 'FactoryERC20V2 จาก TPIX Token Factory'],
        'vesting' => ['สัญญาล็อก 70%', 'TrancheVesting'],
        'presale' => ['สัญญา presale', 'FixedPriceSale'],
        'pair_usdt' => ['พูล IDTC/USDT', 'คู่บน TPIX DEX'],
        'pair_tpix' => ['พูล IDTC/TPIX', 'คู่บน TPIX DEX'],
    ];

    public function edit(Settings $settings, ChainStatus $chain, SalesIndexer $indexer): View
    {
        $overrides = $settings->contractOverrides();
        $rows = [];
        foreach (Settings::CONTRACT_KEYS as $key) {
            $rows[$key] = [
                'label' => self::LABELS[$key][0],
                'hint' => self::LABELS[$key][1],
                'default' => Idtc::isAddress(config("idtc.contracts.{$key}")) ? config("idtc.contracts.{$key}") : null,
                'override' => $overrides[$key] ?? null,
                'effective' => Idtc::contract($key),
            ];
        }

        return view('admin.settings', [
            'title' => 'ตั้งค่าเหรียญ',
            'rows' => $rows,
            'fixed' => array_diff_key(Idtc::contracts(), array_flip(Settings::CONTRACT_KEYS)),
            'startBlock' => $settings->indexerStartBlock(),
            'indexState' => $indexer->state(),
            'rpc' => $chain->diagnose(),
        ]);
    }

    public function contracts(Request $request, Settings $settings, RpcClient $rpc, PresaleReader $presale, VestingReader $vesting, PoolReader $pools, SalesIndexer $indexer, ContractOrigin $origin): RedirectResponse
    {
        $key = (string) $request->input('key');
        if (! in_array($key, Settings::CONTRACT_KEYS, true)) {
            return back()->with('error', 'ไม่รู้จักรายการสัญญานี้');
        }
        $label = self::LABELS[$key][0];
        $input = trim((string) $request->input('address', ''));
        $current = $settings->contractOverrides();
        $old = $current[$key] ?? null;

        if ($input === '') {
            $new = null;
        } else {
            $error = match (true) {
                ! Address::isValid($input) => 'ที่อยู่ต้องขึ้นต้นด้วย 0x ตามด้วยตัวอักษรฐานสิบหก 40 ตัว',
                ! Address::hasValidChecksum($input) => 'ตัวพิมพ์เล็ก/ใหญ่ของที่อยู่ไม่ตรง checksum (อาจพิมพ์ผิด) — คัดลอกใหม่จาก explorer',
                Address::isZero($input) => 'ใช้ที่อยู่ว่าง (0x000…000) ไม่ได้',
                default => null,
            };
            foreach (Idtc::contracts() as $otherKey => $otherAddress) {
                if ($error === null && $otherKey !== $key && Address::same($otherAddress, $input)) {
                    $error = "ที่อยู่นี้ใช้เป็น {$otherKey} อยู่แล้ว — สัญญาแต่ละตัวต้องมีที่อยู่ของตัวเอง";
                }
            }
            if ($error === null) {
                try {
                    if (! $rpc->hasCode($input)) {
                        $error = 'ไม่พบสัญญาที่ที่อยู่นี้บน TPIX Chain (อาจเป็นกระเป๋าธรรมดา หรือยังไม่ได้ deploy)';
                    }
                } catch (RpcException $e) {
                    $error = 'ตรวจที่อยู่กับเชนไม่ได้: '.$e->getMessage();
                }
            }
            if ($error !== null) {
                return back()->withInput()->withErrors(["address_{$key}" => $error])->with('error', "{$label}: {$error}");
            }
            $new = Address::checksum($input);
        }

        if ($old === $new) {
            return back()->with('status', "{$label}: ไม่มีอะไรเปลี่ยน");
        }

        $next = $current;
        if ($new === null) {
            unset($next[$key]);
        } else {
            $next[$key] = $new;
        }
        $by = app(AdminContext::class)->address;
        $settings->put('contracts', $next, $by);
        Audit::change('settings.contract', $old, $new, ['key' => $key]);

        // ตั้งสัญญา presale ครั้งแรก: เริ่มดึงยอดจากบล็อกที่ deploy สัญญา (ไม่พลาดการซื้อที่เกิดก่อนกรอกที่อยู่)
        // หาไม่ได้ (explorer ล่ม/สัญญาสร้างจาก factory) → บล็อกปัจจุบัน · แก้ย้อนหลังได้ในช่องบล็อกเริ่ม
        if ($key === 'presale' && $new !== null && $settings->indexerStartBlock() === null) {
            try {
                $block = $origin->creationBlock($new) ?? $rpc->blockNumber();
                $settings->put('indexer.start_block', $block, $by);
                Audit::change('settings.indexer', null, $block, ['key' => 'indexer.start_block']);
            } catch (RpcException) {
                // ไม่เป็นไร ตัวดึงยอดจะเริ่มที่บล็อกปัจจุบันเองตอนรันครั้งแรก
            }
        }

        $presale->forget();
        $vesting->forget();
        $pools->forget();

        $effective = Idtc::contract($key);

        return back()->with('status', $new === null
            ? "{$label}: ล้างค่าที่ตั้งจากหลังบ้านแล้ว".($effective ? ' (ใช้ค่าจาก .env)' : ' — หน้าเว็บจะแสดงสถานะ "รอ deploy"')
            : "{$label}: บันทึกแล้ว หน้าเว็บสาธารณะใช้ที่อยู่ใหม่ทันที");
    }

    public function indexer(Request $request, Settings $settings, SalesIndexer $indexer): RedirectResponse
    {
        $raw = trim((string) $request->input('start_block', ''));
        if ($raw !== '' && ! preg_match('/^\d{1,12}$/', $raw)) {
            return back()->withInput()->withErrors(['start_block' => 'บล็อกเริ่มต้องเป็นตัวเลขจำนวนเต็มบวก'])->with('error', 'บล็อกเริ่มต้องเป็นตัวเลขจำนวนเต็มบวก');
        }
        $new = $raw === '' ? null : (int) $raw;
        $old = $settings->indexerStartBlock();
        if ($old === $new) {
            return back()->with('status', 'บล็อกเริ่มไม่ได้เปลี่ยน');
        }
        $settings->put('indexer.start_block', $new, app(AdminContext::class)->address);
        $indexer->restartFrom($new);
        Audit::change('settings.indexer', $old, $new, ['key' => 'indexer.start_block']);

        return back()->with('status', $new === null
            ? 'ล้างบล็อกเริ่มแล้ว — สัญญาใหม่จะเริ่มดึงจากบล็อกปัจจุบัน'
            : "ตั้งบล็อกเริ่มเป็น {$new} แล้ว — รอบถัดไปจะดึงยอดตั้งแต่บล็อกนี้ (รายการที่มีอยู่แล้วไม่ซ้ำ)");
    }
}
