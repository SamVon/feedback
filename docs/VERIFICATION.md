# ผลการตรวจ Easy Gold Feedback

## API contract และเอกสาร · 17 พฤศจิกายน 2026

- เพิ่ม metadata ใน `app_config`: source, survey_code, survey_version, published_at และ rating_levels 5–1 โดยรักษา wrapper/fields เดิม
- `php artisan test --compact tests/Feature/FeedbackUxTest.php`: **15 tests ผ่าน / 198 assertions** บน MySQL schema `easy_gold_feedback_test` ที่พอร์ต 3303 รวม metadata ใหม่, default always_on, การแยกคำถามตาม source, comment ของ Pop-up, retry และ rollback
- `vendor/bin/pint --dirty --format agent`: ผ่าน
- ตรวจ OpenAPI JSON: **9 routes** ตรงกับ `php artisan route:list --path=internal/v1/feedback --json`, references **146** รายการ resolve ได้ และ operation IDs ไม่ซ้ำ
- ใช้ Symfony YAML parser ที่มีอยู่ตรวจ OpenCollection, environment และ **11 Internal Service requests**: YAML/JSON bodies ถูกต้อง, มี service headers/idempotency headers, routes อยู่ใน OpenAPI และ variables มีใน environment
- ตรวจ local links ในคู่มือ/API/README แล้ว ไม่เพิ่ม dependencies และไม่เปลี่ยนฐานข้อมูลเว็บ
- การตรวจเอกสารเป็น syntax/reference/route coverage ไม่ใช่ full OpenAPI conformance validator และไม่ได้กดส่ง Bruno requests เข้าฐานข้อมูลจริง; HTTP behavior ตรวจผ่าน feature tests ข้างต้น
- เอกสารใหม่: [คู่มือเชื่อมต่อ](INTEGRATION_TH.md), [OpenAPI 3.1](openapi.json), [Bruno](../../bruno/EasyGold-Feedback/README.md) ขอบเขต App Backend routes และ Admin REST เดิมยังเป็นข้อเสนอ


อัปเดต automated checks วันที่ **17 พฤศจิกายน 2026 · Asia/Vientiane** ในเครื่อง development ไม่มีการ deploy และไม่มีการเรียกใช้ credentials หรือข้อมูล production

## ผล automated checks

| รายการ | ผล |
|---|---|
| PHPUnit ชุดรวมก่อนเพิ่มช่องคำแนะนำใน Pop-up บน MySQL / PHP 8.5.10 | **ผ่าน 52 tests / 573 assertions** |
| หลังแก้กราฟ PDF: FeedbackUxTest บน PHP 8.5.10 | ผ่าน 9 tests / 97 assertions |
| ชุดเดิมบน PHP 8.4.25 | ผ่าน 31 tests / 329 assertions ก่อนเพิ่ม rating reasons |
| Laravel Pint | ผ่าน |
| `composer validate --strict` | ผ่าน |
| `composer check-platform-reqs` บน PHP 8.5.10 | ผ่านทุก requirement |
| `php artisan view:cache` | ผ่าน |
| Migrations ทั้งหมดบน local MySQL 9.7.2 (127.0.0.1:3303) | Ran; InnoDB / READ COMMITTED / UTC |
| แยก secret/local DB/dependencies ออกจาก Git | `.env`, `database/database.sqlite`, `vendor` ถูก ignore |

ผล PHPUnit แบบ machine-readable อยู่ใน [test-results.json](test-results.json) บันทึกจาก PHP 8.5.10 และ MySQL พอร์ต 3303 ชุดทดสอบใช้ `easy_gold_feedback_test` และ schema ชื่อสุ่ม `easy_gold_feedback_test_*`; ไม่มี SQLite fallback และมี guard ป้องกัน RefreshDatabase ทำงานกับฐานข้อมูลเว็บ

## คำถามคะแนนและเหตุผล · 16 พฤศจิกายน 2026

- ทดสอบ config และบันทึกเหตุผลครบคะแนน 1–5; จำนวนที่เลือกได้ตาม published config (ทดสอบเลือก 4 ได้เมื่อกำหนด 4); ปฏิเสธเหตุผลผิดคะแนน/ข้ามคำถาม/ข้าม version/ซ้ำ รวมถึงกรณีบังคับเหตุผลแต่ไม่ส่ง
- Retry คง receipt เดิมและจำนวน answer options เดิม; validation failure ไม่เหลือ response/answer/idempotency ที่บันทึกค้าง
- รองรับเหตุผล optional; clone draft และแก้ไขไม่เปลี่ยนคำถาม published; ทดสอบ Filament actions และ permission
- ตรวจ browser จริง: ตารางแยกคอลัมน์ 5–1 ดาว, ฟอร์มมีห้าแท็บ, Preview เลือกสามข้อแล้วปิดข้อที่สี่, เปลี่ยนคะแนนแล้วล้างตัวเลือกเก่า และแสดงเหตุผลของคะแนนใหม่
- Preview ตรวจคำตอบในหน้า Admin เท่านั้น ไม่บันทึก feedback; ไม่พบ console error ระหว่างตรวจ Preview
- เคยสร้าง DEMO draft v2 ในรอบก่อน; ณ การตรวจล่าสุด v2 และ v3 อยู่สถานะ submitted, v1 published จึงไม่ได้เปลี่ยนหรืออนุมัติรายการเหล่านี้ ข้อความตัวอย่างต้องให้ผู้ดูแลตรวจทั้งสอง locale ก่อนเผยแพร่
- ภาพผลลัพธ์: [Admin rating preview](screenshots/rating-preview.jpg)

## กรณีสำคัญที่ทดสอบ

- Submission ใหม่ได้ 201; retry เดิมได้ 200/body เดิม; key เดิม payload ต่างได้ 409 รวมถึง payload ใหม่ที่ validation ไม่ผ่าน
- Client submission ID เดิมแต่ key ใหม่ไม่สร้างซ้ำ; invalid answers rollback ทั้ง response/idempotency record
- ส่งพร้อมกัน **6 PHP processes** ไป MySQL schema เดียวกัน: 1×201 + 5×200, response ID เดียว, บันทึกเพียงครั้งเดียว
- Worker จำลอง commit สำเร็จแล้วจบโดยไม่ส่ง receipt; request ถัดไปได้ 200 และไม่เพิ่มข้อมูล
- MySQL backup ด้วย mysqldump แบบ single transaction, restore ลง schema ใหม่, ตรวจจำนวนคำตอบ และ retry key เดิมผ่าน process ใหม่หลัง restore
- Service auth, caller scopes, actor ownership และการอ่าน response ข้าม actor
- คะแนน 1–5 แบบ integer, required questions, ข้าม version, choice ข้ามคำถาม, text length และ answer type
- Actor rate limit และ replay หลัง limit เต็ม; auto-prompt ไม่มี invitation/ไม่ยืนยัน success/คนอื่น/หมดอายุถูกปฏิเสธ; disabled channel ส่งใหม่ไม่ได้
- Events มาสลับลำดับ shown/dismissed และ event ID ซ้ำด้วย key ใหม่ไม่เพิ่ม event
- Cohort batches ซ้ำ, manifest ยังไม่ครบ, complete หลังครบ และสถานะ incomplete/unanswered/not_invited; ชื่อเข้ารหัสและไม่คืนให้ผู้ไม่มี identity permission
- 1,500 ratings รวม 6,535 → average **4.36**, 1,275 ratings 4–5 → CSAT **85%**; ไม่มีข้อมูลเป็น null
- Choice denominator นับเฉพาะผู้ตอบคำถามนั้น; multi-select รวมเกิน 100% ได้; timezone boundary 17:00 UTC เป็นวันถัดไปใน Vientiane
- Stale revision ถูกปฏิเสธ, published version คงเดิม, direct service/page ไม่มี permission ถูกปฏิเสธ
- Filament action: create new version → submit → approve → publish ผ่าน application workflow
- Export private file, spreadsheet formula prefix, ตรวจ owner/revoked permission, anonymous redirect และ freeze response membership: คำตอบที่เข้ามาหลังสร้าง export ไม่ถูกเพิ่มในไฟล์
- Staff pages ทุกเมนู render ได้ทั้ง en/lo; Livewire widgets/resources render และตรวจข้อมูลในตารางได้

## การย้ายไป MySQL

- ใช้ MySQL ของ DBngin ที่ `127.0.0.1:3303` ตาม `.env` ที่ผู้ใช้ยืนยัน; ไม่มี MySQL container เพิ่มค้างไว้
- ย้ายข้อมูลเดิม 28 ตาราง / 4,353 แถว รวม 1,500 feedback responses และ 2,060 answers ตรวจจำนวนและ fingerprint ของเนื้อหาทุกแถวก่อน commit; APP_KEY เดิมและข้อมูลเข้ารหัสยังอยู่
- ค่า default/config/example/test runner เปลี่ยนเป็น MySQL และตัดการสร้างไฟล์ SQLite ออกจาก composer setup
- พบ deadlock จริงเมื่อ concurrent requests ใช้ insert-ignore แล้ว upgrade เป็น write lock แก้ actor/idempotency locking เป็น upsert ที่รับ write lock โดยตรง และไม่เขียนทับ payload hash หรือ receipt เดิม
- ทดสอบ concurrency/backup แยกซ้ำอีก 2 รอบหลังแก้ deadlock นอกเหนือจากชุดทดสอบเต็ม
- JSON ที่ MySQL จัดลำดับ key ใหม่ยังตรวจเทียบเนื้อหา/ชนิดข้อมูลได้โดยไม่อาศัยลำดับ object keys

## กฎ Pop-up ที่เพิ่มในรอบนี้

- BUY_GOLD บันทึกแล้วพักเฉพาะ BUY_GOLD 30 วันจากเวลาบันทึก แม้ invitation ออกก่อนหน้านั้น; SALE_GOLD และลูกค้าอื่นยังตอบแยกได้
- shown/dismissed/invalid answer ไม่เพิ่มตัวนับ; จำลอง failure ระหว่าง insert answer หลัง insert response แล้วตรวจ rollback response/answers/idempotency พร้อม quota/cooldown ก่อน retry สำเร็จ
- retry ด้วย key เดิมหรือ client submission เดิมคืน receipt เดิม ไม่เพิ่มตัวนับและไม่เลื่อน cooldown; replay eligibility เดิมตรวจสถานะปัจจุบัน
- ทดสอบก่อนครบ 30 วันหนึ่งวินาทีและขณะครบพอดี; ครบ 3 แล้วรอ 30 วัน/อีกหนึ่งปีและ publish rules ใหม่ก็ไม่รีเซ็ต
- คำขอ always_on ไม่ลดโควตาและไม่กิน anti-abuse bucket ของ Pop-up; ธุรกรรมที่ไม่ยืนยัน success ถูกปฏิเสธ
- **6 PHP processes พร้อมกัน ใช้คนละ invitation/submission/idempotency key**: ได้ 1×201 + 5×422 ทั้งกรณีเริ่มจาก 0 และมี 2 คำตอบเดิม ตรวจจำนวน responses/answers/committed idempotency และเวลาพักให้ตรงกัน รวมทั้ง replay ผู้ชนะไม่เพิ่มจำนวน
- Published rules แบบเก่าอ่านเป็นกฎใหม่โดยไม่เขียนทับประวัติ; config ให้ค่า 3/30/pending_confirmation
- Filament เปิด/ปิด SALE_GOLD แยก BUY_GOLD ผ่าน new-version/approval workflow ได้; รายละเอียดกฎทั้ง en/lo แสดงนโยบายรอยืนยัน และ server ปฏิเสธการเพิ่มเพดานเป็น 4

นโยบายรีเซ็ต/การจัดการเมื่อครบ 3 คำตอบยังเป็น business decision ที่รอยืนยัน ระหว่างนี้ระบบรักษาเพดาน 3 โดยไม่รีเซ็ตอัตโนมัติ

## Browser checks (รอบเดิม 13 พฤศจิกายน 2026)

รอบอัปเดต Pop-up เดิมใช้ API, Livewire/Filament และ multiprocess tests; รอบ UX วันที่ 17 พฤศจิกายนตรวจหน้า Rules ใน browser เพิ่มตามบันทึกด้านล่าง ยังไม่ได้ทดสอบ mobile client


ใช้บัญชี local ชั่วคราวและ DEMO data เท่านั้น ตรวจ:

- Login และ Dashboard จาก browser จริง
- เปลี่ยนวันที่/เลือก version/question แล้ว cards เป็น 4.36, 85%, 1,500 responses, 220 distinct accounts
- Customers แสดง 260 บัญชีจาก complete snapshot; รายละเอียดรายบุคคลมีประวัติและ CSAT; answered แยกจาก not_invited
- Survey form มี rating/choice/text, labels English/Lao และ options
- Desktop 1440px และ tablet 820px; sidebar ยุบได้, ฟอร์มเรียงตามพื้นที่, Lao แสดงผลได้
- กราฟไม่มีข้อมูลแสดง No data และมีคำอธิบายการเลือกคำถาม
- แก้ contrast ของ active sidebar item แล้วตรวจภาพอีกครั้ง
- ไม่พบ JavaScript error ในช่วงตรวจ browser

ออกจากระบบ ปิดบัญชีทดสอบ หมุนรหัสผ่านเป็นค่าที่ไม่เก็บไว้ และลบไฟล์รหัสผ่านชั่วคราวแล้ว ไม่มี credentials ใน screenshot

ภาพจาก browser:

- [Desktop preview](screenshots/dashboard-preview.png)
- [Desktop เต็มหน้า](screenshots/dashboard-desktop.png)
- [Tablet ภาษา Lao](screenshots/dashboard-tablet-lao.png)

## อัปเดต UX/Admin และ Export · 17 พฤศจิกายน 2026

อ่าน PDF `ເອກະສານ Feedback & Rating App Feature (1).pdf` ครบ 11 หน้า และเทียบภาพหน้า 4–8 กับหน้า Admin ที่พัฒนา ใช้หน้า 9–10 เป็นข้อมูลสำหรับ config contract เท่านั้น ไม่อ้างว่าแก้ mobile แล้ว การเปิด Figma ผ่าน connector ถูกปฏิเสธเพราะไม่มีสิทธิ์แก้ไข จึงยังตรวจ node 2554:7732 และ design tokens โดยตรงไม่ได้ ไม่รับรอง pixel-perfect หรือการอนุมัติครบทุกฝ่ายจากช่องลงนามที่ว่าง

| Reference | ส่วนที่ทำและตรวจ | ความต่าง/ข้อจำกัด |
|---|---|---|
| หน้า 4 | โมดูล 4 cards, sidebar แดง/active ทอง, Dashboard, filters, satisfaction/distribution/trend, Excel/PDF | ใช้ Filament components เดิมและข้อมูล DEMO ที่ระบุชัด; label เล็กที่อ่านไม่ชัดเป็น translation keys รอผู้ใช้ภาษาลาวตรวจ |
| หน้า 5 | ประวัติคำถามดาว/เหตุผล 5–1, modal, เพิ่ม/ลบ/เรียง, published config | เลือกจำนวนได้ตาม config; cap 50 เป็นข้อจำกัดทางเทคนิค ไม่ใช่ business requirement จาก mockup |
| หน้า 6 | Other questions หนึ่งคำถามต่อแถว, enabled toggle, choice/text, modal และประวัติ version | แก้รายข้อแล้วบันทึกทั้ง draft version เพื่อรักษา atomic publish; ไม่ทำลายคำถามเก่า |
| หน้า 7 | Rules แยก schedule, type quota/cooldown และ Always-On proposal | 3 ครั้ง/วันยังไม่บังคับใช้; reset quota ยังรอยืนยัน |
| หน้า 8 | Customer Service เฉพาะ Feedback: name/description/icon/enabled | ใช้ไอคอนภายใน star/chat/headset; custom upload รอ app asset contract |
| หน้า 9–10 | published channels/content/questions/reasons/thank-you และ safe app_config | ทีม mobile/backend เดิมยังต้องเชื่อม API และวาดหน้าจอจริง |

**Browser ที่ตรวจจริง:**

- Dashboard ใช้ช่วงวันที่ครอบคลุม DEMO: average 4.36, CSAT 85%, 1,500 responses, 220 distinct accounts; filter `demo-account-001` เหลือ 7 responses / 1 customer / 4.43 / 85.71%
- กด Export PDF → queued → worker → ready → download สำเร็จ ตรวจ PDF 2 หน้าภาษาลาว และแก้กราฟให้ความกว้างแสดง 71.43% / 14.29% / 0% ถูกต้อง จากนั้น render และตรวจทั้งสองหน้าอีกครั้ง
- Other questions: เพิ่มตัวเลือก A/B ในฟอร์มที่ยังไม่บันทึก, เลื่อน B ขึ้น, ลบ A แล้วเหลือ B; ปิด modal โดยไม่สร้างข้อมูลทดลองใหม่
- Customer Service modal มีสถานะ/icon/name/description; ไม่บันทึกหรือ publish เปลี่ยนช่องทางจริง
- Rules modal แสดง schedule และ Always-On ข้อเสนอแยกชัดเจน พร้อมคำอธิบายไม่นับ shown/dismissed และไม่ reset หลัง 30 วัน
- ตรวจ Admin ที่ 390px: modal เรียงแนวตั้ง, document width 390 ไม่มี horizontal overflow, keyboard focus มองเห็นในช่องวันเวลา; ที่ 820px cards เรียง 2 คอลัมน์และ sidebar เปิด/ยุบได้ จากนั้นคืน viewport เดิม
- ไม่พบ console error ในแท็บตรวจรอบนี้ ทั้งหน้าใหม่และ modal render ได้ en/lo ผ่าน automated tests; ไม่อ้างว่าเป็น accessibility audit เต็มรูปแบบ

**Tests ใหม่ 9 กรณี:** published config/disabled questions และ version history, schedule start-inclusive/end-exclusive, ปิด Customer Service ขณะฟอร์มค้างและ retry, Always-On ไม่บังคับข้อเสนอ 3/day, Filament version workflow/permission, XLSX/PDF frozen membership และ formula/HTML injection, configurable reason limit, invalid policy/schedule/icon, สูตรตัวอย่าง PDF 900/375/125/60/40 ได้ 4.36 และ 85% รวมถึง 3 ดาวเป็นกลาง

**Schema/API:** migration `2026_11_16_153710_extend_feedback_ux_configuration` รันบน local MySQL 3303 แล้ว เพิ่ม `survey_versions.channels/content`, `questions.enabled`, `export_jobs.format/locale/summary` โดยไม่แก้คำตอบเดิม Config เพิ่ม `app_config` สำหรับ backend ส่งต่อให้แอป; source ที่ไม่รองรับใน latest published version ตอบ 404 ไม่ fallback ไป version เก่า รายละเอียดและตัวอย่างอยู่ใน [API.md](API.md)

**Dependencies:** เพิ่ม PhpSpreadsheet 5.11.0 / mPDF 8.3.1 ตามอนุญาตแล้ว Export ใช้ queue/owner/permissions/expiry เดิมและ frozen membership; worker แนะนำ PHP memory_limit=512M ชุดทดสอบรวมใช้ 512M เนื่องจาก PDF/XLSX ใช้หน่วยความจำเพิ่ม ไม่มีการเปลี่ยน PHP global config

ภาพผลตรวจ: [Module](screenshots/ux-module.jpg), [Dashboard](screenshots/ux-dashboard.jpg), [Customer Service](screenshots/ux-customer-service.jpg), [Rules จอเล็ก](screenshots/ux-rules-mobile.jpg), [Tablet](screenshots/ux-module-tablet.jpg), [PDF หน้าสรุป](screenshots/ux-export-preview.png)

**สิ่งที่ต้องยืนยัน/ทำต่อในระบบอื่น:** reset/การจัดการครบ 3 คำตอบ, account หรือ One ID, Always-On 3/day และขอบเขตวัน/วันมีผล, ความสัมพันธ์ Always-On กับ Pop-up (ปัจจุบันแยก), จำนวน/ข้อความเหตุผลแต่ละระดับ, คำแปลลาว, custom icon assets และความหมาย transaction success/revert ของแต่ละ workflow ทีม backend เดิมต้องตรวจ session/transaction จาก Balance Service และ sync complete customer snapshot จริง; ทีม mobile ต้องใช้ published config และเก็บ idempotency key เดิมเมื่อ retry ไม่มีการแก้แอปมือถือ, Figma หรือ deploy production

## ขอบเขตที่ยังไม่ยืนยัน

ยังไม่ได้ทดสอบ integration จริงกับ kpv-auth-service/user-service/balance-service, mobile popup, MySQL production topology, distributed deployment, load/SLA, failover, downstream timeout และ production backup drill การตรวจ retry/backup ด้านบนเป็น implementation test ของ service บน MySQL local

ต้องตกลง service credentials, staff SSO/permission/revocation, actor/One ID identity, transaction success/revert และ complete cohort manifest ก่อนเปิดใช้งานจริง รายละเอียดและความต่างจากเอกสารเสนออยู่ใน [API.md](API.md) และ [README](../README.md)

## แยกคำถาม Pop-up / Feedback และ Other Questions รายข้อ · 17 พฤศจิกายน 2026

- `SurveyPresentation`, config controller, `SubmitFeedback`, `Invitations` ใช้ rating-only สำหรับ auto_prompt และ rating + enabled other questions สำหรับ always_on
- `OtherQuestionResource`, `OtherQuestionFields`, `OtherQuestions` เปลี่ยนเป็นรายข้อ: เพิ่ม แก้ไข เปิด/ปิด เอาออก, status badge, วันเผยแพร่เปิด/ปิดของ version, ผู้แก้ไขจาก audit, วันที่/สถานะ/latest filters และ pagination
- ไม่ต้องเพิ่ม migration/dependency; ใช้ enabled และ version/audit เดิม เปลี่ยนคำถามอื่นไม่แก้ rating ใน version นั้น ไม่เผยแพร่หรือเปลี่ยนข้อมูลที่ผู้ใช้สร้างค้างไว้ใน browser ระหว่างตรวจ
- เพิ่ม tests กรณี required other question ไม่บังคับใน Pop-up แต่ยังบังคับในหน้า Feedback, ส่ง other question เข้า Pop-up ถูกปฏิเสธ, disable/enable ผ่าน publish แล้ว config เปลี่ยน, เก็บคำตอบเก่า, เพิ่ม/เรียง/เอาออก, stale revision และ read-only permission, ไม่มี enabled rating แล้วไม่ออก invitation
- ชุดรวมผ่าน 52 tests / 573 assertions บน MySQL ฐานทดสอบพอร์ต 3303 รวม concurrency เดิม ตรวจ browser ฟอร์มรายข้อและสวิตช์เปิด/ปิดโดยไม่บันทึกข้อมูลทดลอง
- วันที่ในตารางเป็นวันที่เผยแพร่ version ที่ enabled/disabled; ไม่อนุมานวันที่เปิด/ปิดย้อนหลังที่ระบบไม่ได้เก็บ ไม่เปลี่ยน retirement policy สำหรับฟอร์มที่ถือ version เก่า
- ตรวจ Preview ใน Rating Questions: ค่าเริ่มต้น Pop-up แสดง rating/เหตุผลเท่านั้น; สลับเป็นหน้า Feedback แล้วแสดง rating + choice + text ตรวจแล้วไม่พบ console error
- ภาพล่าสุด: [ตารางรายข้อ](screenshots/other-questions-status.jpg), [ฟอร์มพร้อมสวิตช์](screenshots/other-question-editor.jpg), [Pop-up rating only](screenshots/popup-rating-only.jpg)

## ช่องคำแนะนำเพิ่มเติมในคำถามดาว Pop-up · 17 พฤศจิกายน 2026

เพิ่ม textarea `ຄຳແນະນຳເພີ່ມເຕີມ` ใน Preview และ comment metadata ใน config รับ `text_value` ร่วมกับคะแนนและเหตุผลใน answer เดียวเฉพาะ auto_prompt เป็น optional และจำกัดตาม question.max_length ใช้คอลัมน์เดิม ไม่เพิ่ม migration ไม่ดึง Other Questions เข้า Pop-up และไม่เพิ่มช่องซ้ำใน always_on

รันชุดที่ได้รับผลกระทบ FeedbackUxTest / RatingReasonsTest / FeedbackTest ผ่าน **39 tests / 415 assertions** รวม comment พร้อมเหตุผล, retry/409 เมื่อแก้ข้อความด้วย key เดิม, cooldown, ความยาวภาษาลาวที่ขอบเขต 500/501 ตัวอักษร, invalid type, null/empty/omitted และคง validation ของ text question เดิม Pint และ view:cache ผ่าน ตรวจ Preview กรอกคะแนน/คำแนะนำและตรวจคำตอบได้ ไม่พบ console error ไม่มีการบันทึกข้อมูลทดลองใน browser

ภาพ: [Pop-up พร้อมช่องคำแนะนำ](screenshots/popup-additional-comments.jpg)

## Rating Questions แบบหน้าเต็ม · 17 พฤศจิกายน 2026

- เพิ่ม `EditRatingQuestion` resource page และ Blade `filament/resources/pages/edit-rating-question` ปุ่มสร้าง/แก้ Draft/สร้าง version ใน Rating Questions เป็นลิงก์ไปหน้าเต็ม ส่วน Preview ยังคงเป็น modal สำหรับดูตัวอย่าง
- ขั้นแรกตั้งข้อความคำถาม English/Lao และแสดงตารางระดับดาว 5, 4, 3, 2, 1 ตายตัว ตั้งค่าจำนวนที่เลือก/required/ความยาวคำแนะนำอยู่ในส่วนพับได้ แต่ละดาวเปิดขั้นตอนแก้ตัวเลือกเต็มหน้า เพิ่ม ลบ และเรียงได้
- Apply answer set เก็บชุดคำตอบในฟอร์มและกลับตาราง; Save draft บันทึกทุกชุดพร้อมกันผ่าน Changes เดิม มีข้อความอธิบายชัดว่า Apply ยังไม่เขียนฐานข้อมูล Back ในขั้นตอนรายดาวทิ้งเฉพาะการแก้ชุดนั้น
- เก็บ revision และ version ID/status ฝั่ง server ด้วย Livewire Locked ตรวจ permission ทุกครั้งที่บันทึก/เข้าขั้นตอน ตรวจ stale draft, ไม่แก้ published/submitted เดิม, รักษา Other Questions/content/channels ของ version ต้นทาง สามารถเลือกคำถามดาวใน version ที่มีหลายข้อผ่านลิงก์แต่ละข้อ
- ไม่เพิ่ม schema/dependency หรือเปลี่ยนกฎ Pop-up; ข้อความเหตุผลเริ่มว่างในแบบสอบถามใหม่ ไม่สร้างข้อความจาก mockup
- RatingReasonsTest + FeedbackTest ผ่าน **27 tests / 266 assertions**; หลังจัดกลุ่มฟอร์มและแก้การเลื่อนเมื่อเปลี่ยนขั้นตอน รัน RatingReasonsTest ซ้ำผ่าน **8 tests / 102 assertions** รวม create/edit/reorder/apply/cancel/save, default 5 levels, old version preservation, stale revision, permission และ validation ของข้อความ
- ตรวจ browser: ลิงก์เปิดหน้าเต็ม ไม่มี dialog ในฟอร์ม แก้ชุด 5 ดาวแล้วเลื่อน Fast service ขึ้นก่อน Easy to use และ Apply กลับตาราง โดยไม่กด Save draft ไม่เปลี่ยนข้อมูลผู้ใช้ ตรวจ preview screenshots: [หน้าตั้งคำถาม](screenshots/rating-question-page.jpg), [หน้าแก้คำตอบรายดาว](screenshots/rating-answer-page.jpg)
