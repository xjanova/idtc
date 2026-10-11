# IDTC explainer — script v1 (English VO · Thai subtitles · 16:9 · ~90 s · CapCut)

Presenter: the AI woman from ChatGPT (navy blazer with emerald piping, IDTC pin, logo A).
**LIP** = presenter speaks to camera (Digen Lip Gen). **B-ROLL** = website images and graphics under the VO.

| # | Time | Shot | English VO | ซับไทย |
|---|------|------|-----------|--------|
| 1 | 0:00–0:09 | LIP · p1 medium, logo screen behind | Hi, let me introduce IDTC, the Invest Energy-Development Coin: a token built to support energy development, on a chain where every rule can be checked. | สวัสดีค่ะ ขอแนะนำ IDTC เหรียญเพื่อการพัฒนาพลังงาน บนเชนที่ตรวจสอบได้ทุกกติกา |
| 2 | 0:09–0:21 | B-ROLL · hero solar farm → wind → storage | In 2025, the world is set to invest about three point three trillion dollars in energy, according to the International Energy Agency. Yet most people can't take part. IDTC Energy Capital aims to change that, starting with solar, wind and energy storage across Asia and the Middle East. | ปี 2025 โลกลงทุนด้านพลังงานราว 3.3 ล้านล้านดอลลาร์ (IEA) แต่คนส่วนใหญ่เข้าไม่ถึง IDTC Energy Capital ตั้งใจเปลี่ยนเรื่องนี้ เริ่มจากโซลาร์ ลม และระบบกักเก็บพลังงาน ในเอเชียและตะวันออกกลาง |
| 3 | 0:21–0:31 | LIP · p3 left third + chain card on the right | IDTC lives on TPIX Chain, chain ID 4289, where gas fees are zero. You can buy, sell and send without holding any other coin. | IDTC อยู่บนเชน TPIX (Chain ID 4289) ค่าแก๊สเป็นศูนย์ ซื้อ ขาย โอนได้โดยไม่ต้องถือเหรียญอื่น |
| 4 | 0:31–0:47 | B-ROLL · donut 1.1T, then the glass vault, then the unlock curve | The supply is fixed at one point one trillion. Seventy percent is locked in a smart contract that has no owner. It unlocks slowly: one-, two-, three- and four-year tranches, each released monthly over the following year. There is never a big unlock day. | จำนวนเหรียญคงที่ 1.1 ล้านล้าน ล็อก 70% ในสัญญาที่ไม่มีเจ้าของ ปลดทีละน้อยเป็น 4 ก้อน 1–4 ปี แต่ละก้อนทยอยปลดรายเดือนตลอดปีถัดไป ไม่มีวันปลดก้อนใหญ่ |
| 5 | 0:47–0:56 | LIP · p2 close-up | And the code can't be changed. No minting, no pausing, no blacklists and no early withdrawals, not even by the team. | และโค้ดแก้ไม่ได้ ไม่มีการสร้างเหรียญเพิ่ม ไม่มีการหยุดโอน ไม่มีแบล็กลิสต์ และถอนก่อนกำหนดไม่ได้ แม้แต่ทีมงานเอง |
| 6 | 0:56–1:10 | B-ROLL · presale card UI, price in gold, bridge image | The presale price is fixed in the contract at zero point zero zero zero zero zero one five dollars per IDTC. Pay with USDT and your tokens arrive in the same transaction. Holding USDT on BNB Chain? Bridge it to TPIX one-to-one. | ราคา presale ฝังในสัญญาที่ $0.0000015 ต่อ IDTC จ่ายด้วย USDT แล้วได้เหรียญในธุรกรรมเดียวกัน มี USDT บน BNB Chain ก็ส่งผ่านสะพานมาเชน TPIX ได้ 1:1 |
| 7 | 1:10–1:18 | B-ROLL · "Add network" button + wallet app tiles | One tap on our website adds TPIX Chain and IDTC to MetaMask, Trust Wallet, OKX and more. | กดปุ่มเดียวบนเว็บ เพิ่มเชน TPIX และเหรียญ IDTC ลง MetaMask, Trust Wallet, OKX และอื่น ๆ |
| 8 | 1:18–1:30 | LIP · p1 medium → end card (logo + idtc.online + risk line) | Every number in this video points back to a verified contract. Read the whitepaper, check the explorer, and decide for yourself. IDTC: energy development you can verify. Visit idtc.online. | ทุกตัวเลขในคลิปนี้ชี้กลับไปที่สัญญาที่ verified อ่านไวท์เปเปอร์ ตรวจบน explorer แล้วตัดสินใจเอง IDTC พัฒนาพลังงานแบบตรวจสอบได้ · idtc.online |

End card line (Thai only): สินทรัพย์ดิจิทัลมีความเสี่ยง ราคาอาจผันผวน ไม่ใช่คำแนะนำการลงทุน

## Production
- VO: MiniMax speech-2.8-hd, English female, warm and confident (~1,250 characters).
- Lip-sync: Digen Lip Gen-3.1 for shots 1, 3, 5 and 8 (≈ 40 s × 5 credits/s ≈ 200 credits).
- B-roll: the 8 website images, plus graphics rendered with Playwright (donut, unlock curve, presale card, wallet tiles).
- Edit: CapCut draft built by a script (adapted from the WinXTools builder), with native Thai subtitles in Kanit, music ducked under the VO, -14 LUFS.

## Facts to verify before VO
- IEA World Energy Investment 2025 (June 2025): investment is projected to reach USD 3.3 trillion in 2025, about USD 2.2 trillion of it clean energy. These are early estimates, so the VO says "is set to invest". Checked 2026-10-11 at https://iea.org/reports/world-energy-investment-2025/executive-summary.
- Gas price 0 on TPIX Chain: confirmed live (eth_gasPrice = 0x0).
- Presale rate 2e18/3 per micro-USDT = 666,666.67 IDTC per USDT: covered by the LaunchTools tests.
