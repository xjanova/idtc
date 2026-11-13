<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GridRound;
use App\Services\Admin\AdminContext;
use App\Services\Admin\Audit;
use App\Services\Chain\RewardsReader;
use App\Services\Chain\Units;
use App\Services\Grid\GridLedger;
use App\Services\Grid\GridRounds;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * หลังบ้าน IDTC Grid › รอบจ่าย — สร้างรายชื่อจากยอดครบระยะ แล้วเจ้าของสัญญาประกาศ root บนเชนจากกระเป๋าในเบราว์เซอร์
 * (เซิร์ฟเวอร์ไม่ถือคีย์ ไม่ส่งธุรกรรมเอง · สถานะรอบตัดสินจากเชนทุกครั้งที่เปิดหน้า)
 */
class GridRoundsController extends Controller
{
    public const KIND = ['full' => 'รอบเต็ม', 'instant' => 'ส่วนรับได้ทันที', 'deferred' => 'ส่วนปลดหลังปิดการขาย'];

    public const STATUS = [
        GridRound::DRAFT => 'รอประกาศบนเชน',
        GridRound::LIVE => 'ประกาศแล้ว รับได้',
        GridRound::REVOKED => 'ยกเลิกบนเชนแล้ว',
        GridRound::STALE => 'ใช้ไม่ได้ (เลขรอบนี้บนเชนเป็นรายชื่ออื่น)',
        GridRound::DISCARDED => 'ทิ้งแล้ว',
    ];

    public function __construct(protected GridRounds $rounds, protected RewardsReader $reader, protected GridLedger $ledger) {}

    public function index(): View
    {
        $this->rounds->reconcile();
        $chain = $this->reader->read();
        $due = $this->rounds->due($this->ledger->book());

        return view('admin.grid.rounds', [
            'title' => 'รอบจ่ายรางวัล Grid',
            'chain' => $chain,
            'excess' => ($chain['ok'] ?? false) && Units::cmp($chain['balance'], $chain['outstanding']) > 0
                ? Units::sub($chain['balance'], $chain['outstanding']) : '0',
            'list' => GridRound::query()->where('stream', GridRounds::STREAM)->orderByDesc('id')->limit(100)->get(),
            'draft' => $this->rounds->draft(),
            'due' => $due,
            'dueSum' => array_sum(array_column($due, 'due')),
        ]);
    }

    public function show(GridRound $round): View
    {
        abort_unless($round->stream === GridRounds::STREAM, 404);
        $this->rounds->reconcile();
        $round->refresh();
        $chain = $this->reader->read();
        $drafts = GridRound::query()->where('stream', GridRounds::STREAM)->where('status', GridRound::DRAFT)->orderBy('chain_round')->get();

        return view('admin.grid.round', [
            'title' => 'รอบจ่ายที่ '.$round->chain_round,
            'round' => $round,
            'chain' => $chain,
            'on' => $chain['rounds'][$round->chain_round] ?? null,
            'items' => $round->entries()->orderByDesc('amount_idtc')->paginate(100),
            // ประกาศได้เฉพาะรอบ draft ที่เลขรอบตรงกับรอบถัดไปบนเชน (ทีละรอบตามลำดับ)
            'publishable' => $round->status === GridRound::DRAFT && ($chain['ok'] ?? false)
                && $round->chain_round === (int) $chain['count'] && $drafts->first()?->is($round),
        ]);
    }

    public function build(Request $request): RedirectResponse
    {
        $note = trim((string) $request->input('note', ''));
        if (mb_strlen($note) > 500) {
            return back()->with('error', 'หมายเหตุยาวเกิน 500 ตัวอักษร');
        }
        $res = $this->rounds->build(app(AdminContext::class)->address, $note !== '' ? $note : null);
        if (! $res['ok']) {
            return back()->with('error', $res['message']);
        }
        Audit::record('grid.round.build', [
            'rounds' => array_map(fn (GridRound $r) => ['id' => $r->id, 'chain_round' => $r->chain_round, 'kind' => $r->kind, 'root' => $r->root, 'total' => $r->total, 'items' => $r->items], $res['rounds']),
        ]);

        return redirect()->route('admin.grid.round', $res['rounds'][0])->with('status', $res['message']);
    }

    public function discard(GridRound $round): RedirectResponse
    {
        abort_unless($round->stream === GridRounds::STREAM, 404);
        $this->rounds->reconcile();
        $round->refresh();
        if (! $this->rounds->discard($round)) {
            return back()->with('error', 'ทิ้งได้เฉพาะรอบที่ยังไม่ได้ประกาศบนเชน');
        }
        Audit::record('grid.round.discard', ['id' => $round->id, 'chain_round' => $round->chain_round, 'root' => $round->root]);

        return redirect()->route('admin.grid.rounds')->with('status', 'ทิ้งรอบที่ '.$round->chain_round.' (และรอบที่สร้างต่อจากนั้น) แล้ว — ยอดกลับไปรอรอบถัดไป');
    }

    public function export(GridRound $round): StreamedResponse
    {
        abort_unless($round->stream === GridRounds::STREAM, 404);
        $name = 'idtc-grid-round-'.$round->chain_round.'-'.$round->kind.'.csv';

        return response()->streamDownload(function () use ($round) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['contract', 'round', 'kind', 'root', 'wallet', 'amount_wei', 'amount_idtc', 'proof']);
            foreach ($round->entries()->orderBy('wallet')->cursor() as $i) {
                fputcsv($out, [$round->contract, $round->chain_round, $round->kind, $round->root, $i->wallet, $i->amount,
                    number_format($i->amount_idtc, 6, '.', ''), implode(' ', $i->proof)]);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
