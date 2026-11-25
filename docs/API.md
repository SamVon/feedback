# Internal API ที่ implement

คู่มือเริ่มเชื่อมต่อ: [INTEGRATION_TH.md](INTEGRATION_TH.md) · Contract สำหรับเครื่องมือ: [OpenAPI 3.1](openapi.json) · [Bruno](../../bruno/EasyGold-Feedback/README.md)

Base path `/internal/v1/feedback` — server-to-server เท่านั้น ไม่ใช่ API สำหรับ mobile/browser โดยตรง

ทุก request ใช้:

```http
Authorization: Bearer <service-token>
X-Service-ID: app-backend
Accept: application/json
Content-Type: application/json
```

Write requests ต้องมี `Idempotency-Key: <stable-operation-key>` อักขระ `A-Z a-z 0-9 . _ : -` ความยาว 1–128 เก็บ key เดิมและ body เดิมเมื่อ retry JSON object key order ไม่มีผล แต่ array order/value/type และ whitespace ในข้อความมีผล

Caller `app-backend` ใช้ hash ใน `FEEDBACK_APP_TOKEN_SHA256`; caller `user-adapter` ใช้ `FEEDBACK_COHORT_TOKEN_SHA256` แต่ละ token ต้องแยกกัน ว่างหมายถึงปฏิเสธทุก request ไม่ใช้ token ลูกค้าหรือ JWT พนักงานแทน service token

| Method | Path | Caller | ผล |
|---|---|---|---|
| GET | `/config?survey_code=demo-experience` | app-backend | 200; survey version ล่าสุด + questions/options + rules แยก transaction type |
| POST | `/eligibility` หรือ `/invitations` | app-backend | 201 เมื่อออก invitation; 200 replay/ไม่เข้าเงื่อนไข |
| POST | `/prompt-events` | app-backend | 201 หลัง commit; 200 replay |
| POST | `/responses` | app-backend | 201 หลัง commit; 200 replay |
| GET | `/responses/{id}?actor_user_id=...` | app-backend | 200 เฉพาะ caller และ actor ที่ตรงกัน |
| POST | `/cohort-snapshots` | user-adapter | 201 หลังสร้าง manifest; 200 replay |
| POST | `/cohort-snapshots/{id}/members` | user-adapter | 201 หลัง commit batch; 200 replay |
| POST | `/cohort-snapshots/{id}/complete` | user-adapter | 200 เมื่อ count และ batches ครบ |

API ชุดนี้ไม่มี external Staff Admin REST API: panel ใช้ session + application services ภายใน

## Config

```bash
curl --fail-with-body 'http://127.0.0.1:8000/internal/v1/feedback/config?survey_code=demo-experience' \
  -H "Authorization: Bearer $FEEDBACK_SERVICE_TOKEN" \
  -H 'X-Service-ID: app-backend' -H 'Accept: application/json'
```

ผลเป็น `{ "survey": { "id": "<UUID>", "version": 1, "questions": [...] }, "rules": {...} }` คำถามและตัวเลือกมี UUID ของ version นั้น ไม่ใช้ stable code เป็น ID ใน submission มี label `{en,lo}`; Cache-Control `private,max-age=60`

## ส่วนขยาย UX/UI · 17 พฤศจิกายน 2026

Migration `2026_11_16_153710_extend_feedback_ux_configuration` เพิ่ม `survey_versions.channels/content`, `questions.enabled` และ `export_jobs.format/locale/summary` แบบ additive; คำถามเดิม enabled=true, survey เดิมที่ channels=null รองรับทั้งสองช่องทาง ไม่เขียนทับ published version หรือคำตอบเก่า

Config รับ `source=auto_prompt|always_on` เพิ่มเติมได้ ถ้ารุ่น published ล่าสุดของ survey code นั้นไม่เปิดช่องทางที่ขอ จะตอบ 404; ไม่ fallback ไปเลือกรุ่นเก่าให้เอง การรับคำตอบยังตรวจช่องทางของ version ที่ระบุ แบบสอบถาม published เดิมยังรับคำตอบตามกฎปัจจุบันได้ จนกว่าจะตกลง retirement policy

### แยกคำถามตามช่องทาง · ยืนยัน 17 พฤศจิกายน 2026

- Pop-up ต้องเรียก config ด้วย `source=auto_prompt`: คืนเฉพาะคำถาม `rating` ที่ enabled พร้อมเหตุผลตามคะแนน 1–5; ไม่ส่งคำถาม `choice`/`text` จาก Other Questions
- หน้า Feedback เรียก `source=always_on`: คืนทั้ง rating และ choice/text ที่ enabled ตามลำดับ
- ใช้กฎเดียวกันกับทั้ง `survey.questions`, `app_config.questions` และการตรวจ submission: ส่ง Other Question เข้า auto_prompt ได้ 422; required ของ Other Questions ไม่ทำให้ Pop-up บังคับตอบคำถามที่ไม่ได้แสดง
- คำถาม rating ใน Pop-up มีช่องคำแนะนำเพิ่มเติมแบบ optional (`ຄຳແນະນຳເພີ່ມເຕີມ`) เป็นส่วนหนึ่งของคำตอบดาว ไม่ใช่ Other Question เพิ่ม `comment: {channels:["auto_prompt"], field:"text_value", required:false, max_length:<question.max_length>, label:{en:"Additional comments",lo:"ຄຳແນະນຳເພີ່ມເຕີມ"}}` ใน config คำถามดาว
- ส่ง `text_value` พร้อม `question_id`, `rating_value` และ `option_ids` (เมื่อเปิดเหตุผล) ใน answer เดียวได้เฉพาะ auto_prompt ไม่ส่ง/ส่ง null/ส่งค่าว่างได้; ข้อความเกิน max_length ตอบ 422 บันทึกพร้อมคะแนน/เหตุผล/receipt แบบ atomic และข้อความเป็นส่วนหนึ่งของ idempotency payload
- หน้า Feedback ยังใช้คำถาม text ของ Other Questions สำหรับความคิดเห็นตาม version; ไม่เพิ่ม textarea ของ rating ซ้ำใน always_on
- ทุก question มี `channels`: rating เป็น `["auto_prompt","always_on"]`, choice/text เป็น `["always_on"]` สำหรับ adapter แยกช่องทาง
- กรณีไม่ส่ง source ยังคงคืนชุดคำถามสำหรับหน้า Feedback เพื่อรักษา contract เดิม; backend ที่เรียก Pop-up ต้องส่ง source ให้ถูกต้อง ไม่ส่งชุดคำถามรวมไปวาด Pop-up
- ถ้าไม่มีคำถามดาว enabled: `app_config.channels.auto_prompt=false`, config ของ Pop-up มี questions ว่าง และ eligibility ปฏิเสธ 422
- คำตอบ retry ที่บันทึกสำเร็จก่อนเปลี่ยนกฎยังคืน receipt เดิมตาม idempotency
- Other Questions แสดงหนึ่งคำถามต่อแถว เปิด/ปิด แก้ไข เพิ่ม และเอาออกผ่าน draft version โดยรักษาคำถามดาว/คำถามอื่นที่ไม่ได้แก้ พร้อม revision check และ audit; ต้องอนุมัติ/เผยแพร่ก่อน config เปลี่ยน
- สถานะและวันเปิด/ปิดในตารางอ้างอิง version ที่แสดง: วันเป็น `published_at` ของ version ที่ enabled/disabled ไม่ใช่ schedule รายคำถาม และไม่มีวันสำหรับ draft/submitted; ประวัติ version เก่ายังดูได้เมื่อปิด filter latest
- แบบฟอร์มที่ถือ published version เก่ายังคงใช้ความหมายของ version นั้นจนกว่าจะยืนยัน retirement policy; การปิดคำถามใน version ใหม่ไม่เขียนทับคำถาม/คำตอบเก่า

Response คง `survey` และ `rules` สำหรับ app backend เดิม และเพิ่ม `app_config` สำหรับเลือกส่งต่อให้แอป โดย metadata ใหม่ `source`, `survey_code`, `survey_version`, `published_at` และ `rating_levels` ช่วยระบุช่องทาง/รุ่น/ลำดับคะแนน ตัวอย่างต่อไปนี้ตัดรายละเอียด questions ออก:

```json
{
  "app_config": {
    "source": "auto_prompt",
    "survey_code": "demo-experience",
    "survey_version": 2,
    "published_at": "2026-11-17T04:00:00Z",
    "rating_levels": [5, 4, 3, 2, 1],
    "survey_version_id": "<published UUID>",
    "channels": {"auto_prompt": true, "always_on": true},
    "content": {
      "title": {"en": "Feedback & rating", "lo": "<localized title>"},
      "intro": {"en": "<introduction>", "lo": "<localized introduction>"},
      "submit": {"en": "Send feedback", "lo": "<localized button>"},
      "cancel": {"en": "Cancel", "lo": "<localized button>"},
      "thank_you": {"en": "Thank you", "lo": "<localized message>"}
    },
    "questions": ["<enabled questions in configured order>"],
    "customer_service": {"enabled": true, "icon": "star", "name": {"en": "Feedback & rating", "lo": "<name>"}, "description": {"en": "", "lo": ""}},
    "popup_schedule": {"starts_at": null, "ends_at": null}
  }
}
```

`app_config.channels` เป็นสถานะรับคำตอบ ณ เวลาอ่าน รวมสวิตช์ช่องทาง, ช่องทางของ survey และ schedule สำหรับ Pop-up ไม่มี staff secrets, anti-abuse rate limit หรือข้อเสนอ policy ที่ยังไม่บังคับใช้ใน projection นี้ `customer_service.enabled` เป็นสถานะเปิดหน้า Feedback ที่รวมสวิตช์ Always-On เดิมแล้ว ส่วน `channels.always_on` ตรวจการรองรับของ survey เพิ่มด้วย แอปต้องใช้ `channels.always_on` ก่อนเปิดให้ตอบ และต้องเรียก eligibility ก่อนแสดง Pop-up เสมอ ไม่ใช้ config แทนการตรวจ actor/transaction Config ส่ง `Cache-Control: private, no-store` เพื่อให้อ่านสถานะล่าสุดทุกครั้ง

`content` เก็บกับ survey version; คำถาม text ใช้เป็นความคิดเห็นเพิ่มเติมและกำหนด required/max_length ได้ การปิด/ลบคำถามทำใน draft/new version เท่านั้น Config ไม่ส่งคำถาม disabled; submission ของ version นั้นต้องไม่ส่งคำถาม disabled ด้วย IDs ของ published questions/options ไม่เปลี่ยนย้อนหลัง; การสร้าง version ใหม่ออก UUID ใหม่ โดยคง `code` เป็นรหัสอ้างอิงเชิงตรรกะ ไม่ใช้ code แทน UUID ใน submission

### Rules และ Customer Service

- `popup_schedule.starts_at/ends_at`: UTC ISO 8601 (`YYYY-MM-DDTHH:MM:SSZ`) หรือ null; Admin กรอก Asia/Vientiane เริ่มรวมขอบเขต และสิ้นสุดไม่รวมขอบเขต ไม่มีค่าแปลว่าไม่จำกัดด้านนั้น ใช้หลัง publish เท่านั้น ตรวจทั้ง eligibility และส่งคำตอบใหม่ แยกจาก cooldown 30 วันหลัง commit
- `always_on_frequency` แสดงจำนวนคำตอบต่อวันใน Internal Config เมื่อผู้ดูแลบันทึกค่าจากแท็บ Rules ระบบตอบ `status: "active"` และ `enforced: true` กฎนับคำตอบ `always_on` ที่บันทึกสำเร็จต่อบัญชีและเริ่มนับใหม่ตอนเที่ยงคืนตามเวลาเวียงจันทน์ เมื่อครบจำนวน API ตอบ `429` พร้อม `Retry-After` การจำกัดอัตราส่งและกฎ Pop-up ใช้เงื่อนไขเดิม
- `customer_service`: แยกสถานะเข้าถึง Feedback จาก Auto-Prompt; `/admin/settings` เปิดหน้ารายการ `/admin/customer-service` ปุ่มแก้ไขเปิด Modal สวิตช์ “ເປີດໜ້າຄຳຄິດເຫັນ” อยู่ที่นี่เพียงจุดเดียวและบันทึก `customer_service.enabled` กับ `channels.always_on` ให้ตรงกัน หน้า Rules รักษาสถานะนี้ขณะเปลี่ยน Pop-up บันทึกแบบร่างแล้วเผยแพร่จึงมีผลกับ API โดยรักษา transaction policy และ schedule ใช้ revision และขั้นตอนเผยแพร่เดิม ไม่แก้รายการบริการอื่น
- `customer_service.icon` ยังคงเป็น `star|chat|headset` สำหรับแอปเดิม เพิ่ม `customer_service.icon_url` ใน Config เป็น URL รูปไอคอนที่เผยแพร่แล้วหรือ null แอปที่รองรับรูปให้ใช้ `icon_url` ก่อน แล้วใช้ไอคอนมาตรฐานเป็น fallback ไม่ส่ง storage path ภายในใน app config
- เจ้าหน้าที่อัปโหลดได้เฉพาะ PNG/JPG/WebP ไม่เกิน 1 MB ผ่าน Modal ภาพอยู่บน public storage และแสดงใน Config หลังเผยแพร่ ไม่รับ URL ภายนอก/SVG หรือ path นอกโฟลเดอร์ไอคอน และไม่ลบรูปที่รุ่นเดิมยังอ้างอิง
- เมื่อปิด Customer Service หรือช่องทาง Always-On หลัง publish คำตอบใหม่ รวมถึงฟอร์มที่เปิดค้างไว้ จะตอบ 422; ข้อมูลเก่าไม่ถูกลบ และ retry ของคำตอบที่ commit สำเร็จแล้วยังคืน receipt เดิม 200
- เมื่อ schedule จบ การส่งคำตอบ Pop-up ใหม่จาก invitation ที่ยังไม่หมดอายุก็ถูกปฏิเสธ 422; retry ที่เคยบันทึกแล้วไม่ถูกปฏิเสธหรือเริ่ม cooldown ใหม่
- การเปลี่ยนเวอร์ชันไม่รีเซ็ต successful-response quota ของ Pop-up; การนับบัญชี/One ID, reset หลังครบสาม, Always-On ต่อ cooldown และเวลาเริ่มใช้กฎธุรกิจใหม่ยังต้องยืนยัน

### รายงานและไฟล์ส่งออก

Dashboard, Feedback list และ Reports ใช้ `ReportFilters` ร่วมกัน เพิ่ม `actor_user_id` และ `rating` (1–5) นอกเหนือจากช่วงวันที่, survey/version/question, source และ transaction_type เดิม ถ้ากรองคะแนนพร้อม question_id คะแนนต้องเป็นของคำถามนั้น Always-On ไม่มี transaction_type จึงไม่ปนในผลของตัวกรองธุรกรรม

Reports สร้าง CSV, Excel `.xlsx`, PDF `.pdf` แบบ queue; Dashboard มี Export PDF ค่า snapshot เก็บสมาชิก response ที่เข้าเงื่อนไข และ summary จากสมาชิกชุดเดียวกัน ณ เวลาขอ export จึงไม่รวมคำตอบใหม่ที่มาทีหลัง PDF ใช้ Dhyana/OpenType สำหรับภาษาลาว; Excel มี Responses และ Summary (filters, average, CSAT, distribution, trend, choice denominator) ข้อความจากผู้ใช้ถูกเขียนเป็น string ไม่ใช่สูตร Excel และถูก escape ใน PDF

ใช้ `report.export` + `feedback.read`; summary มีเฉพาะผู้มี `analytics.read` และตรวจสิทธิ์นี้ซ้ำตอน worker/download เมื่อไฟล์มี summary ไฟล์เก็บ private, จำกัดเจ้าของ, หมดอายุตาม export job เดิม ไม่ส่งชื่อ/เบอร์ลูกค้าเพิ่มในไฟล์ export

## Always-on response

```json
{
  "client_submission_id": "submission-example-001",
  "actor_user_id": "<verified-session-account-id>",
  "one_id": null,
  "survey_version_id": "<UUID from config.survey.id>",
  "source": "always_on",
  "submitted_at": "2026-11-12T12:00:00Z",
  "answers": [
    {"question_id": "<rating question UUID>", "rating_value": 5},
    {"question_id": "<optional text question UUID>", "text_value": "Easy to use"},
    {"question_id": "<optional choice question UUID>", "option_ids": ["<option UUID>"]}
  ],
  "metadata": {"app_version": "1.0.0", "os": "android"}
}
```

คำถามทั่วไปมี answer field ตามชนิด (`rating_value`, `text_value`, `option_ids`); rating ที่เปิดเหตุผลรับ `rating_value` พร้อม `option_ids` ตามรายละเอียดด้านล่าง ต้องมีคำถาม required ครบ ห้ามคำถามซ้ำ/ข้าม version; คะแนนต้องเป็น JSON integer ไม่ใช่ string; text ไม่ว่างและไม่เกิน `max_length`; choice อยู่ใน question และไม่เกิน `max_choices` โดย schema กำหนด 50 questions/options สูงสุด

### เหตุผลแยกตามคะแนน 5, 4, 3, 2, 1 ดาว

Config ของคำถาม rating เพิ่ม `rating_reasons_enabled`, `rating_reasons_required` และ `rating_reasons` ซึ่งจัดกลุ่มตัวเลือกตาม key `"5"` ถึง `"1"` แต่ละตัวเลือกมี `id`, `code`, `label` (en/lo), `rating_value`, `position` และเรียงตามลำดับที่ Admin กำหนด `options` แบบรายการเดิมยังอยู่ด้วย คำถามที่ไม่เปิดเหตุผลมี `rating_reasons: []`

ส่งคะแนนและเหตุผลใน answer เดียวกัน เช่น:

```json
{"question_id":"<rating UUID>","rating_value":5,"option_ids":["<reason UUID for 5 stars>"]}
```

- เหตุผลต้องเป็นของคำถาม/version และคะแนนที่ส่งเท่านั้น ห้าม ID ซ้ำ เลือกได้ไม่เกิน `max_choices` ซึ่ง Admin กำหนดใน version นั้น (ขีดจำกัดเชิงเทคนิค 1–50)
- ถ้า `rating_reasons_required` เป็น true ต้องเลือกอย่างน้อยหนึ่งข้อ เมื่อ false ส่ง `option_ids: []` หรือไม่ส่ง field นี้ได้
- คำถาม rating เดิมที่ไม่เปิดเหตุผลยังส่งเฉพาะ `rating_value`; ไม่อนุญาตให้เพิ่ม `option_ids` ให้คำถามนั้น
- auto_prompt ส่งคำแนะนำเพิ่มเติมเป็น `text_value` ใน answer ของ rating ได้แบบ optional; always_on ส่งความคิดเห็นเป็น answer ของคำถามชนิด text แยกต่างหาก เมื่อเปลี่ยนคะแนน client ต้องล้างเหตุผลจากคะแนนเดิม
- Admin ต้องตั้งชุดเหตุผลครบทั้ง 5 คะแนน แต่ละคะแนนมี 0–50 ตัวเลือก (ขีดจำกัดเชิงเทคนิค); ถ้าบังคับเหตุผลต้องมีอย่างน้อย 1 ตัวเลือกทุกคะแนน เพิ่ม/ลบ/เรียงลำดับได้ เก็บเหตุผลที่เลือกใน answer options เดิม จึงอ่านย้อนหลังและส่งออก CSV ได้
- การบันทึกคะแนนและเหตุผลอยู่ใน transaction/idempotency เดียวกับ response ไม่เปลี่ยนกฎโควตา 3 คำตอบหรือ cooldown 30 วันต่อ actor/transaction type

จัดการผ่าน `/admin/rating-questions` โดยใช้สิทธิ์ `survey.read` / `survey.edit` และ workflow draft → submitted → approved → published เดิม ตารางเป็นประวัติ survey version ที่มีคำถามคะแนน Preview ใน Admin ใช้ตรวจการเลือกดาว/เหตุผลโดยไม่ส่งคำตอบเข้าฐานข้อมูล การเชื่อมกับแอปมือถือจริงยังอยู่นอกขอบเขตการเปลี่ยนแปลงนี้

`submitted_at` ต้องรูปแบบ UTC `YYYY-MM-DDTHH:MM:SSZ` ไม่อยู่ในอนาคต; `received_at` สร้างโดย service เอง ระบบนี้เก็บ metadata เฉพาะ app version และ OS (`android|ios|other`) ไม่เก็บ device model

```bash
curl --fail-with-body -X POST http://127.0.0.1:8000/internal/v1/feedback/responses \
  -H "Authorization: Bearer $FEEDBACK_SERVICE_TOKEN" -H 'X-Service-ID: app-backend' \
  -H 'Idempotency-Key: submission-example-001' -H 'Content-Type: application/json' \
  -H 'Accept: application/json' --data-binary @submission.json
```

ผล `201` หลัง transaction commit:

```json
{"response_id":"<UUID>","client_submission_id":"submission-example-001","status":"stored","received_at":"2026-11-12T12:00:02Z"}
```

Replay เดิมได้ `200` กับ body เดิม แม้ invitation หมดอายุหรือ rule เปลี่ยนหลัง commit แล้ว Key เดิมแต่ payload ต่างได้ `409`; client submission เดิมของ actor/caller เดิมแต่ใช้ key ใหม่จะคืนผลเดิมถ้า payload ตรง ไม่เพิ่มคำตอบ ไม่ลบ idempotency records อัตโนมัติ

Submission ไม่รับ `name` และ `phone` ใน body ทั้ง `auto_prompt` และ `always_on` หากส่งฟิลด์เหล่านี้พร้อมค่าจะได้ `422` รวมถึง internal submission endpoint

Mobile API `/api/v1/feedback/responses` ใช้ `_id` จาก Customer JWT เป็น actor และอ่านชื่อจาก `first_name`/`last_name` กับเบอร์จาก `phone` หลังตรวจลายเซ็นแล้ว ชื่อ/เบอร์เป็นข้อมูลประกอบ ไม่ใช้แทน actor หรือยืนยัน KYC ข้อมูลที่ไม่มีหรือไม่ถูกต้องจะไม่ถูกบันทึก เก็บโปรไฟล์ที่ใช้ได้ใน `customer_name`/`customer_phone` แบบเข้ารหัส และไม่คืนใน receipt ข้อมูล JWT นี้แยกจาก payload hash เพื่อให้ retry เดิมทำงานได้แม้ token ต่ออายุ และไม่แก้ข้อมูลคำตอบเดิม

ตาราง Customers และ Feedback ยังคงแสดงชื่อและเบอร์ภายใต้ `customer_identity.read` ข้อมูลเก่าที่เคยบันทึกไว้ยังคงอยู่ ตัวอย่าง Mobile API อยู่ใน `APP_FEEDBACK_API_TH.md`

## Auto-prompt: backend ต้องตรวจสำเร็จก่อน

POST `/eligibility`:

```json
{
  "actor_user_id": "<verified-session-account-id>",
  "survey_version_id": "<UUID>",
  "transaction_context": {
    "transaction_id": "<verified transaction ID>",
    "transaction_type": "BUY_GOLD",
    "transaction_occurred_at": "2026-11-12T11:59:00Z",
    "verified_at": "2026-11-12T11:59:01Z",
    "success_verified": true
  }
}
```

`success_verified` เป็น assertion จาก service ที่ตรวจ credential แล้ว ไม่ใช่หลักฐานจาก mobile client; backend ต้องตรวจ source-of-truth transaction, actor/ownership, success และ revert policy ก่อนเรียก route นี้ Feedback Service ไม่ได้เชื่อม balance-service เพื่อพิสูจน์ซ้ำ

201:

```json
{
  "eligible": true,
  "reason": null,
  "transaction_type": "BUY_GOLD",
  "successful_response_count": 0,
  "max_responses": 3,
  "remaining_responses": 3,
  "cooldown_days": 30,
  "cooldown_until": null,
  "reset_policy": "pending_confirmation",
  "invitation_id": "<UUID>",
  "survey_version_id": "<UUID>",
  "expires_at": "2026-11-13T11:59:01Z"
}
```

200 เมื่อไม่เข้าเงื่อนไข โดย `eligible=false` และ `reason` เป็น `cooldown_active`, `response_limit_reached` หรือ `trigger_disabled` พร้อมตัวนับ/เวลาพักข้างต้น

ตรวจสิทธิ์ปัจจุบันแม้ replay eligibility ด้วย key เดิม จึงไม่คืน `eligible=true` จาก cache หลังบันทึกคำตอบหรือปิด rule แล้ว Context หนึ่งออก invitation ต่อ survey version ได้ครั้งเดียว; invitation ที่ตอบแล้ว/หมดอายุให้ `invitation_answered`/`invitation_expired` หาก key เดิมเคยถูกปฏิเสธและตอนนี้ผ่านกฎแล้ว จะได้ `evaluation_retry_requires_new_key`: การตรวจครั้งใหม่ต้องใช้ key ใหม่ ควรตรวจ eligibility กับ backend ก่อนแสดงทุกครั้ง และตรวจ `eligible`/`expires_at` เสมอ

ส่งคำตอบด้วย schema always-on โดยเปลี่ยน `source=auto_prompt` และเพิ่ม `invitation_id` **ไม่ส่ง `transaction_context` ใน response**: service ใช้ context ที่ผูกกับ invitation เท่านั้น กรณี always-on ห้ามแนบ invitation

## กฎ Pop-up แยกประเภทธุรกรรม

ขอบเขตคือ `(actor_user_id, transaction_type)` ของ response `source=auto_prompt` ที่บันทึกสำเร็จ ทุก survey/rule version ใช้ประวัติเดียวกัน การเปลี่ยน published version หรือ caller ไม่รีเซ็ตตัวนับ ต้องใช้ canonical actor ID เดียวกันจาก backend; ยังไม่รวมหลายบัญชีด้วย One ID

- จำกัด 3 responses ต่อขอบเขตข้างต้น นับ response หนึ่งครั้ง ไม่ใช่จำนวนคำถามใน response
- `cooldown_until = MAX(received_at) + 30 วัน` ของประเภทนั้น ใช้เวลาบันทึกฝั่ง service ที่ commit สำเร็จใน UTC ไม่ใช้เวลา invitation, shown, dismissed หรือ client submitted_at
- ก่อนครบ 30 วันจะไม่ออกสิทธิ์เพิ่ม เมื่อถึงเวลาพอดีจะประเมินใหม่ แต่ยังนับคำตอบเดิม หากครบ 3 จะถูกปฏิเสธต่อไปในระหว่างรอยืนยันนโยบาย
- **Business decision ที่ยังรอยืนยัน:** จะรีเซ็ตเมื่อใด และจัดการลูกค้าที่ครบ 3 คำตอบอย่างไร ไม่มี scheduled reset, rolling 30-day quota หรือ manual reset ในรุ่นนี้ การบล็อกเมื่อครบ 3 เป็นการรักษาเพดานระหว่างรอข้อสรุป ไม่ใช่การยืนยันว่าจำกัดตลอดชีพ
- BUY_GOLD ไม่ลดโควตา/เริ่มพัก SALE_GOLD; always_on ไม่ลดโควตา/เริ่มพัก Pop-up ใด ๆ และยังไม่ผูก transaction type อัตโนมัติ
- ออก invitation, แสดง/ปิด Pop-up, คำตอบที่ rollback และ retry คำตอบเดิมไม่นับเพิ่มหรือเลื่อนเวลาพัก
- ตรวจซ้ำตอน submit ภายใต้ actor lock หากเคยออกหลาย invitation ก่อนตอบ คำขอถัดไปถูกปฏิเสธด้วย 422 หลังมีคำตอบแรก commit แล้ว; retry คำตอบเดิมคืน 200/body เดิม

ใช้ MySQL/InnoDB (`READ COMMITTED`, timezone UTC) และ response ledger เป็นตัวนับและแหล่งเวลา cooldown โดยตรง ไม่เก็บตัวนับซ้ำอีกตาราง การตรวจ quota, insert response/answers และบันทึก idempotency receipt อยู่ใน DB transaction เดียวและล็อก actor ก่อนอ่านประวัติ การ rollback จึงยกเลิกทุกส่วนพร้อมกัน การล็อก actor อาจจัดลำดับคำขอต่างประเภทของคนเดียวกัน แต่ไม่รวมโควตาของแต่ละประเภทเข้าด้วยกัน

หน้า Rules และ Approvals แสดงกฎตาม type; สร้าง draft และเปิด/ปิดแต่ละประเภทได้ผ่าน workflow เดิม ค่า 3/30 และ `pending_confirmation` เป็นค่าคงที่ที่ server ตรวจด้วย ตัวอย่าง rules ใน config/draft:

```json
{
  "channels": {"auto_prompt": true, "always_on": true},
  "transaction_rules": [
    {"transaction_type": "BUY_GOLD", "enabled": true, "max_responses": 3, "cooldown_days": 30, "reset_policy": "pending_confirmation"},
    {"transaction_type": "SALE_GOLD", "enabled": true, "max_responses": 3, "cooldown_days": 30, "reset_policy": "pending_confirmation"}
  ],
  "rate_limit": 5,
  "rate_window_seconds": 3600,
  "invitation_ttl_seconds": 86400
}
```

กฎ published เดิมที่ใช้ `transaction_types`/`cooldown_seconds` ถูกแปลงเป็น effective policy ข้างต้นขณะอ่าน โดยไม่แก้ JSON ประวัติเดิม ค่า cooldown เก่าไม่ถูกนำมาบังคับใช้ การบันทึก draft ใหม่ต้องใช้ `transaction_rules` คำตอบ auto_prompt เดิมที่มี context ถูกต้องยังนับรวม จึงไม่เริ่มโควตาใหม่เมื่ออัปเดตระบบ ไม่ต้องสร้าง migration สำหรับตัวนับ

`rate_limit`/`rate_window_seconds` เป็น anti-abuse limit เพิ่มเติม แยกต่อ actor/source และ transaction type สำหรับ auto_prompt ไม่ใช่นโยบายรีเซ็ต 3 คำตอบ; always_on จึงไม่กิน bucket ของ Pop-up

## Prompt events

```json
{
  "actor_user_id":"<verified account ID>",
  "event_id":"event-example-001",
  "invitation_id":"<UUID>",
  "event_type":"shown",
  "occurred_at":"2026-11-12T12:00:00Z"
}
```

อนุญาต shown/dismissed, event time อยู่ในอายุ invitation และไม่อยู่อนาคต รับ event มาช้าได้ถ้า occurred_at ถูกต้อง ตรวจ caller/actor ownership, dedup `(caller,event_id)` พร้อม hash; ID เดิม payload ต่างตอบ 409 เก็บเหตุการณ์แยก ไม่ใช้ event ล่าสุดทับ shown จึงรองรับ dismissed ที่มาถึงก่อน shown

ถ้า event มาก่อน invitation ที่มีอยู่จะตอบ 404 ให้ backend retry ภายหลัง ไม่มีกล่องพัก orphan events อัตโนมัติ

## Cohort snapshot

เริ่มด้วย caller `user-adapter`:

```json
{"external_id":"users-snapshot-001","captured_at":"2026-11-12T12:00:00Z","expected_count":2,"expected_batches":1,"identity_type":"account"}
```

รับ `{ "snapshot_id": "<UUID>", "status": "incomplete" }` แล้ว POST members (สูงสุด 500 ราย/batch):

```json
{"batch_id":"batch-001","members":[
  {"actor_user_id":"account-001","one_id":null,"display_name":"<authorized minimal name>","phone":null,"identity_complete":true},
  {"actor_user_id":"account-002","one_id":null,"identity_complete":false}
]}
```

ชื่อ/เบอร์ optional และต้องเป็น fields ที่เจ้าของ user-service อนุญาต ห้าม KYC/ธนาคาร สมาชิกซ้ำระหว่าง batches ได้ 409; batch ID/payload เดิม replay ได้ แม้เปลี่ยน idempotency key จำนวน batch/member ต้องไม่เกิน manifest

POST complete ด้วย body `{}` เมื่อจำนวน unique accounts และ batches ตรง manifest จึงเปลี่ยนเป็น complete และแก้สมาชิกไม่ได้ Snapshot ว่างต้อง `expected_count=0, expected_batches=0` แล้ว complete โดยไม่ส่ง empty batch

หน้า Customers ใช้ complete snapshot ที่ captured_at ใหม่สุด; ถ้ามี incomplete ใหม่กว่าให้เตือน ถ้าไม่มี complete เลยจะแสดง partial latest พร้อม incomplete ทุกคน หากไม่มีข้อมูลเลยจะแสดง no snapshot แหล่งข้อมูลต้องรับรองว่า manifest เป็นลูกค้าทั้งหมด ไม่ใช่ pagination ที่เปลี่ยนระหว่าง sync

## Error / retry

```json
{"error":{"code":"CONFLICT","message":"Idempotency key reused with different payload.","request_id":"<UUID>"}}
```

401 ตัวตนผิด, 403 scope/permission, 404 resource/ownership, 409 conflict/revision, 413 body >2 MiB, 422 invalid data, 429 rate limit (`Retry-After`), 500/503 operational failure ตาม error ที่เกิด เพิ่ม `fields` เมื่อ validation ไม่ผ่าน และ `X-Request-ID` ตรงกับ body; ไม่เผยแพร่ exception trace ของ internal API

เมื่อ transport timeout/5xx ให้ backoff และ retry body/key เดิม คำตอบจะเป็น 201 หรือ 200 ตามว่า commit เกิดหรือไม่ อย่าสร้าง key ใหม่เพียงเพราะไม่ได้รับผล

## ความต่างที่ตั้งใจจากแบบเสนอ

1. Config มี wrapper `survey` + `rules`; UUID เป็น concrete IDs ในฐานข้อมูล ไม่ใช่ logical strings ในตัวอย่างเอกสาร
2. Eligibility ที่สร้าง invitation ตอบ 201; ไม่เข้าเงื่อนไข/replay ตอบ 200; `/invitations` เป็น alias ที่ใช้กฎเดียวกัน
3. Prompt events และ cohort ใช้ synchronous commit 201/200 **ไม่ใช้ 202** จึงไม่มีการอ้างว่ามี durable ingestion queue ที่ยังไม่ได้ทำ
4. Transaction success/context รับที่ eligibility และห้าม redefine ตอนส่ง response; `verified_at` และ `success_verified` เป็น field ที่เพิ่มเพื่อให้ boundary ชัดเจน
5. ไม่มี mobile-facing route/SSO adapter ที่ใช้งานจริง, ไม่มี `received_by_app_backend_at`/device fields ใน storage, ไม่มี actor aggregation แบบ One ID และไม่มี external Admin API
6. Rules มี published version ล่าสุดหนึ่งชุดต่อ service ภายในแยกกฎตาม transaction type; quota/cooldown แยก actor/type และใช้ประวัติข้าม survey/rule versions ตามรายละเอียดข้างต้น
7. ไม่คำนวณ response rate จาก invitation เพราะยังไม่ได้ยืนยัน population/time-window/late-event semantics

ต้องให้ทีม backend review contract นี้ก่อนเริ่มเชื่อมจริง ไม่ถือว่าตัวอย่าง API เดิมเปิดใช้งานแล้ว
