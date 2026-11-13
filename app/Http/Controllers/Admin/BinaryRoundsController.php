<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GridRound;
use App\Services\Admin\AdminContext;
use App\Services\Admin\Audit;
use App\Services\Binary\BinaryRounds;
use App\Services\Chain\Units;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * รอบจ่ายของแผน Binary โปรโมชั่น — สองสาย: usdt (รางวัลจับคู่ จ่าย USDT) · idtc (ส่ง IDTC ที่ซื้อ)
 * ขั้นตอนเหมือนรอบจ่าย Grid: สร้างรายชื่อ → โอนเหรียญเข้าสัญญา → เจ้าของประกาศ root ด้วยกระเป๋าตัวเอง → สมาชิกกดรับที่หน้า /binary
 * (เซิร์ฟเวอร์ไม่ถือคีย์ ไม่ส่งธุรกรรมเอง · สถานะรอบตัดสินจากเชนทุกครั้งที่เปิดหน้า)
 */
class BinaryRoundsController extends Controller
{
    /** ชื่อใน URL => สาย */
    public const STREAMS = ['usdt' => BinaryRounds::USDT, 'idtc' => BinaryRounds::IDTC];

    protected function rounds(string $slug): BinaryRounds
    {
        return BinaryRounds::of(self::STREAMS[$slug] ?? abort(404));
    }

    protected function slugOf(GridRound $round): string
    {
        return array_search($round->stream, self::STREAMS, true) ?: abort(404);
    }

    /** @return array<string, mixed> ค่าที่ view ทั้งสองหน้าใช้เหมือนกัน */
    protected function shared(string $slug, BinaryRounds $rounds): array
    {
        return [
            'slug' => $slug,
            'label' => BinaryRounds::LABELS[$rounds->stream()],
            'unit' => $rounds->unit(),
            'decimals' => $rounds->stream() === BinaryRounds::USDT ? 6 : 18,
            'action' => BinaryRounds::CONTRACT[$rounds->stream()],   // คำนำหน้าคำสั่งบนเชน: binpay.* | bindel.*
            'minDue' => $rounds->minDue(),
        ];
    }

    public function index(string $stream): View
    {
        $rounds = $this->rounds($stream);
        $rounds->reconcile();
        $chain = $rounds->reader()->read();
        $due = $rounds->dueNow();

        return view('admin.binary.rounds', $this->shared($stream, $rounds) + [
            'title' => 'รอบจ่าย Binary — '.BinaryRounds::LABELS[$rounds->stream()],
            'chain' => $chain,
            'excess' => ($chain['ok'] ?? false) && Units::cmp($chain['balance'], $chain['outstanding']) > 0
                ? Units::sub($chain['balance'], $chain['outstanding']) : '0',
            'list' => GridRound::query()->where('stream', $rounds->stream())->orderByDesc('id')->limit(100)->get(),
            'draft' => $rounds->draft(),
            'due' => $due,
            'dueSum' => array_sum(array_column($due, 'due')),
        ]);
    }

    public function show(GridRound $round): View
    {
        $slug = $this->slugOf($round);
        $rounds = $this->rounds($slug);
        $rounds->reconcile();
        $round->refresh();
        $chain = $rounds->reader()->read();
        $drafts = GridRound::query()->where('stream', $round->stream)->where('status', GridRound::DRAFT)->orderBy('chain_round')->get();

        return view('admin.binary.round', $this->shared($slug, $rounds) + [
            'title' => 'รอบจ่าย Binary ที่ '.$round->chain_round,
            'round' => $round,
            'chain' => $chain,
            'on' => $chain['rounds'][$round->chain_round] ?? null,
            'items' => $round->entries()->orderByDesc('amount_idtc')->paginate(100),
            // ประกาศได้เฉพาะรอบ draft ที่เลขรอบตรงกับรอบถัดไปบนเชน (ทีละรอบตามลำดับ)
            'publishable' => $round->status === GridRound::DRAFT && ($chain['ok'] ?? false)
                && $round->chain_round === (int) $chain['count'] && $drafts->first()?->is($round),
        ]);
    }

    public function build(Request $request, string $stream): RedirectResponse
    {
        $rounds = $this->rounds($stream);
        $note = trim((string) $request->input('note', ''));
        if (mb_strlen($note) > 500) {
            return back()->with('error', 'หมายเหตุยาวเกิน 500 ตัวอักษร');
        }
        $res = $rounds->build(app(AdminContext::class)->address, $note !== '' ? $note : null);
        if (! $res['ok']) {
            return back()->with('error', $res['message']);
        }
        Audit::record('binary.round.build', [
            'stream' => $rounds->stream(),
            'rounds' => array_map(fn (GridRound $r) => ['id' => $r->id, 'chain_round' => $r->chain_round, 'root' => $r->root, 'total' => $r->total, 'items' => $r->items], $res['rounds']),
        ]);

        return redirect()->route('admin.binary.round', $res['rounds'][0])->with('status', $res['message']);
    }

    public function discard(GridRound $round): RedirectResponse
    {
        $slug = $this->slugOf($round);
        $rounds = $this->rounds($slug);
        $rounds->reconcile();
        $round->refresh();
        if (! $rounds->discard($round)) {
            return back()->with('error', 'ทิ้งได้เฉพาะรอบที่ยังไม่ได้ประกาศบนเชน');
        }
        Audit::record('binary.round.discard', ['stream' => $round->stream, 'id' => $round->id, 'chain_round' => $round->chain_round, 'root' => $round->root]);

        return redirect()->route('admin.binary.rounds', $slug)->with('status', 'ทิ้งรอบที่ '.$round->chain_round.' (และรอบที่สร้างต่อจากนั้น) แล้ว — ยอดกลับไปรอรอบถัดไป');
    }

    public function export(GridRound $round): StreamedResponse
    {
        $slug = $this->slugOf($round);
        $name = 'idtc-binary-'.$slug.'-round-'.$round->chain_round.'.csv';

        return response()->streamDownload(function () use ($round) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['stream', 'contract', 'round', 'root', 'wallet', 'amount_raw', 'amount', 'proof']);
            foreach ($round->entries()->orderBy('wallet')->cursor() as $i) {
                fputcsv($out, [$round->stream, $round->contract, $round->chain_round, $round->root, $i->wallet, $i->amount,
                    number_format($i->amount_idtc, 6, '.', ''), implode(' ', $i->proof)]);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
