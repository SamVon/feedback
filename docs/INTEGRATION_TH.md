# คู่มือเชื่อมต่อ Easy Gold Feedback API

อัปเดต 17 พฤศจิกายน 2026 · ใช้กับ Feedback Service ใน repository นี้

เอกสารนี้อธิบาย API ที่มีอยู่จริง: **Backend เดิม → Feedback Service** ส่วน App Backend routes สำหรับมือถือยังต้องพัฒนาในโครงการของทีมเจ้าของ และ Filament Admin ใช้ session/application services ภายใน ไม่มี Admin REST API ตามเอกสารข้อเสนอเดิม

- [OpenAPI 3.1 JSON](openapi.json) — สำหรับนำเข้าเครื่องมือที่รองรับ OpenAPI 3.1
- [รายละเอียดกฎและ API](API.md)
- [Bruno collection](../../bruno/EasyGold-Feedback/README.md) — ใช้โฟลเดอร์ `02 Internal Service`

## สิ่งที่อัปเดต

`GET /config` เพิ่ม `app_config.source`, `survey_code`, `survey_version`, `published_at` และ `rating_levels: [5,4,3,2,1]` โดยคง `survey`, `rules` และ fields เดิมไว้ Backend จึงระบุชุดคำถามและ version ที่ส่งให้มือถือได้ชัดเจน

| ช่องทาง | คำถามที่ config ส่งกลับ | วิธีส่งความคิดเห็น |
|---|---|---|
| `auto_prompt` — Pop-up หลังธุรกรรม | rating ที่เปิดใช้งาน พร้อมเหตุผลตามคะแนน | `text_value` ใน answer ของ rating เป็น optional; label `ຄຳແນະນຳເພີ່ມເຕີມ` |
| `always_on` — หน้า Feedback | rating และ Other Questions ชนิด choice/text ที่เปิดใช้งาน | answer ของ text question แยกต่างหาก; rating ไม่รับ `text_value` |

หน้า Admin ตั้งคะแนน 5,4,3,2,1 ไว้เป็นชุดเริ่มต้น แต่ข้อความเหตุผลเป็นข้อมูลที่ Admin ต้องกำหนดตามแต่ละคะแนน ไม่ใช้เหตุผลหรือ UUID จากภาพตัวอย่างเป็นค่าใน production การบันทึก draft ยังไม่เปลี่ยน config จนกว่าจะผ่าน workflow และ publish

## URL, authentication และเวลา

Base URL ตัวอย่าง local: `http://127.0.0.1:8000/internal/v1/feedback` เปลี่ยน host/port ให้ตรง server ที่รันอยู่ พอร์ต MySQL `3303` เป็นพอร์ตฐานข้อมูล ไม่ใช่พอร์ต HTTP

ทุก request ส่ง:

```http
Authorization: Bearer <service token>
X-Service-ID: app-backend
Accept: application/json
```

ทุก POST เพิ่ม `Content-Type: application/json` และ `Idempotency-Key` ความยาว 1–128 ตัวอักษรจาก `A–Z a–z 0–9 . _ : -` ตัวอย่าง `popup-submission-001`

| Caller | การใช้งาน | ตัวแปร hash ที่ server ใช้ตรวจ token |
|---|---|---|
| `app-backend` | config.read, response.read, response.write, invitation.write | `FEEDBACK_APP_TOKEN_SHA256` |
| `user-adapter` | cohort.write เท่านั้น | `FEEDBACK_COHORT_TOKEN_SHA256` |

สำหรับ flow ปัจจุบัน `kpv-balance-service` เป็นผู้เรียก Internal API หลังตรวจเจ้าของและสถานะธุรกรรมสำเร็จจากฐานข้อมูลของตน ค่า `app-backend` เป็นชื่อ caller/credential ที่ Feedback รองรับอยู่แล้ว ไม่ต้อง deploy บริการชื่อ App Backend เพิ่ม สามารถเปลี่ยนเป็นชื่อ `balance-service` ภายหลังได้เมื่อ provision credential และปรับการตั้งค่าทั้งสองฝั่งพร้อมกัน

Caller ส่ง **token ต้นฉบับ** ไม่ใช่ hash; server เก็บ SHA-256 สำหรับตรวจสอบ แยก token ของสอง service และเก็บใน backend เท่านั้น ไม่ส่ง service token ลงแอปมือถือ Customer JWT และ Staff session ใช้แทน service token ไม่ได้

เวลา request ต้องเป็น UTC รูปแบบวินาทีเต็ม เช่น `2026-11-17T05:00:00Z` และไม่อยู่ในอนาคต `verified_at` ต้องไม่น้อยกว่า `transaction_occurred_at` ตัวอย่างทั้งหมดเป็นข้อมูลสมมติ ต้องแทน timestamp และ IDs ก่อนส่งจริง ทุก question/option/invitation/version ใช้ UUID จากระบบ ไม่ใช้ code แทน ID

## API ที่ใช้งานได้

| Method | Path ต่อจาก base URL | Caller | ผลสำเร็จ |
|---|---|---|---|
| GET | `/config?survey_code=...&source=...` | app-backend | 200 |
| POST | `/eligibility` | app-backend | 201 invitation ใหม่; 200 replay/ไม่เข้าเงื่อนไข |
| POST | `/invitations` | app-backend | alias ของ eligibility |
| POST | `/prompt-events` | app-backend | 201 commit; 200 replay |
| POST | `/responses` | app-backend | 201 commit; 200 replay |
| GET | `/responses/{id}?actor_user_id=...` | app-backend | 200 เฉพาะ caller/actor เดิม |
| POST | `/cohort-snapshots` | user-adapter | 201 manifest ใหม่; 200 replay |
| POST | `/cohort-snapshots/{id}/members` | user-adapter | 201 batch ใหม่; 200 replay |
| POST | `/cohort-snapshots/{id}/complete` | user-adapter | 200 เมื่อจำนวนครบ |

## Flow ของ Pop-up

สำหรับ App Backend ที่ยังไม่ใช้ One ID ให้ใช้เบอร์โทรที่ตรวจจากบัญชีลูกค้าปัจจุบันสำหรับจับคู่ข้อมูล Balance โดยไม่ส่ง `one_id` ในคำตอบ; `actor_user_id` ยังคงเป็นรหัสบัญชีจาก customer session เพื่อให้โควตา/ประวัติไม่เปลี่ยนตามการเปลี่ยนเบอร์ ดู [แผนทดสอบ Local ด้วยเบอร์โทร](../../EASY_GOLD_LOCAL_BALANCE_FEEDBACK_TEST_AND_APP_BACKEND_PLAN_TH.md)

1. Backend อ่าน actor จาก session ที่ตรวจสอบแล้ว ตรวจเจ้าของธุรกรรมและผลสำเร็จจากระบบธุรกรรมเดิม ไม่เชื่อ `success_verified` ที่ส่งจากมือถือ
2. อ่าน config ด้วย `source=auto_prompt` ใช้ `app_config` ส่งต่อเฉพาะข้อมูลที่แอปต้องใช้
3. เรียก eligibility ด้วย actor, published version และ transaction context ที่ตรวจสอบแล้ว
4. แสดง Pop-up เฉพาะ `eligible=true` และ invitation ยังไม่หมดอายุ บันทึก shown/dismissed ผ่าน prompt-events ตามเหตุการณ์จริง
5. เมื่อเลือกระดับดาว ใช้เหตุผลจาก `rating_reasons[คะแนน]` ของคำถามนั้น ล้างเหตุผลเดิมเมื่อเปลี่ยนคะแนน
6. ส่งคำตอบพร้อม invitation และ idempotency key ของ submission นี้ Backend ตรวจสิทธิ์อีกครั้งตอนบันทึก invitation ไม่ได้จองโควตาล่วงหน้า
7. แสดงว่าสำเร็จเมื่อได้รับ receipt `status=stored` เท่านั้น หาก timeout ให้ retry payload และ key เดิม

### อ่าน config

```bash
curl --fail-with-body \
  'http://127.0.0.1:8000/internal/v1/feedback/config?survey_code=demo-experience&source=auto_prompt' \
  -H "Authorization: Bearer $FEEDBACK_SERVICE_TOKEN" \
  -H 'X-Service-ID: app-backend' -H 'Accept: application/json'
```

โครงสร้างตอบกลับคือ `{survey, rules, app_config}` ให้ backend เลือกส่ง `app_config` แก่ mobile ไม่ส่งกฎภายในทั้งหมดโดยอัตโนมัติ `app_config` มี metadata ใหม่, `survey_version_id`, `channels`, `content`, `questions`, `customer_service`, `popup_schedule`

ตัวอย่างส่วน metadata (ตัด fields อื่นออกเพื่อแสดงเฉพาะส่วนที่เพิ่ม):

```json
{
  "source": "auto_prompt",
  "survey_code": "demo-experience",
  "survey_version": 2,
  "published_at": "2026-11-17T04:00:00Z",
  "rating_levels": [5, 4, 3, 2, 1],
  "survey_version_id": "11111111-1111-4111-8111-111111111111"
}
```

ทุกคำถามมี `id`, `code`, `type`, `label:{en,lo}`, `required`, `max_length`, `max_choices`, `channels`, `options` และข้อมูลเหตุผลตามคะแนน เมื่อเปิดเหตุผล `rating_reasons` เป็น object keys `"5"` ถึง `"1"` แต่เมื่อไม่เปิดจะเป็น array ว่าง `[]` อย่าสมมติว่า type เป็น object ทุกครั้ง คำถามดาวมี `comment` metadata; ตรวจ `comment.channels` ก่อนแสดง textarea

Config ใช้ published version ล่าสุดของ survey code นั้น ถ้าระบุ source ที่รุ่นนั้นไม่รองรับได้ 404 ไม่ fallback ไป version เก่า ถ้าไม่ระบุ source จะคืนชุด always_on เพื่อรองรับ caller เดิม Pop-up จึงต้องระบุ source เสมอ `Cache-Control: private, max-age=60` เป็น cache config ไม่ใช่ cache สิทธิ์รายลูกค้า

`channels.auto_prompt=true` ไม่รับประกันว่า actor นั้นเข้าเงื่อนไข ต้องเรียก eligibility อีกครั้ง ถ้าไม่มี rating ที่เปิดใช้ config จะคืน questions ว่างและ auto_prompt=false; การขอ eligibility ได้ 422

### ตรวจสิทธิ์และรับ invitation

`POST /eligibility` หรือ `/invitations` ใช้ body เดียวกัน เลือกเรียกหนึ่งเส้นทาง ทั้งสองใช้ idempotency scope ร่วมกัน

```json
{
  "actor_user_id": "account-example-001",
  "survey_version_id": "11111111-1111-4111-8111-111111111111",
  "transaction_context": {
    "transaction_id": "buy-example-001",
    "transaction_type": "BUY_GOLD",
    "transaction_occurred_at": "2026-11-17T04:58:00Z",
    "verified_at": "2026-11-17T04:59:00Z",
    "success_verified": true
  }
}
```

ผล 201 เมื่อสร้าง invitation:

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
  "invitation_id": "44444444-4444-4444-8444-444444444444",
  "survey_version_id": "11111111-1111-4111-8111-111111111111",
  "expires_at": "2026-11-18T04:59:00Z"
}
```

ผล 200 อาจมี `eligible=false` และเหตุผลต่อไปนี้ ต้องตรวจ body ไม่ใช่ดูเพียง HTTP success:

| reason | ความหมาย |
|---|---|
| `trigger_disabled` | ปิดช่องทางหรือ transaction type นี้ |
| `outside_schedule` | อยู่นอกช่วงเปิด Pop-up |
| `cooldown_active` | ยังไม่ครบ 30 วันจากคำตอบสำเร็จล่าสุดของ type นี้ |
| `response_limit_reached` | บันทึกครบ 3 คำตอบของ type นี้แล้ว |
| `invitation_answered` | invitation นี้มีคำตอบแล้ว |
| `invitation_expired` | invitation หมดอายุ |
| `evaluation_retry_requires_new_key` | key เดิมเคยไม่ผ่านและยังไม่มี invitation; ตรวจครั้งใหม่ด้วย key ใหม่ |

Eligibility retry ตรวจ policy ปัจจุบันใหม่ จึงอาจได้ `eligible=false` หลังคำตอบอื่น commit แล้ว ต่างจากการ retry submission ที่คืน receipt เดิม

### ส่งคะแนน เหตุผล และคำแนะนำเพิ่มเติม

`POST /responses` — ตัวอย่างสมมติว่าคำถามดาวเปิดเหตุผลและ option นี้เป็นเหตุผลสำหรับ 5 ดาว:

```json
{
  "client_submission_id": "popup-example-001",
  "actor_user_id": "account-example-001",
  "survey_version_id": "11111111-1111-4111-8111-111111111111",
  "source": "auto_prompt",
  "invitation_id": "44444444-4444-4444-8444-444444444444",
  "submitted_at": "2026-11-17T05:00:00Z",
  "answers": [
    {
      "question_id": "22222222-2222-4222-8222-222222222222",
      "rating_value": 5,
      "option_ids": ["33333333-3333-4333-8333-333333333333"],
      "text_value": "ບໍລິການດີ"
    }
  ],
  "metadata": {"app_version": "2.1.3", "os": "ios"}
}
```

`text_value` เป็น optional สำหรับ rating ใน Pop-up: ไม่ส่ง, null หรือค่าว่างได้ แต่ข้อความต้องไม่เกิน max_length ของคำถาม คะแนนต้องเป็น JSON integer `5` ไม่ใช่ string `"5"` เมื่อไม่เปิดเหตุผลให้ละ `option_ids` เมื่อบังคับเหตุผลต้องเลือกอย่างน้อยหนึ่งข้อและไม่เกิน max_choices ห้ามใช้เหตุผลต่างคะแนน/คำถาม/version

ไม่ส่ง `transaction_context` ใน submission เพราะผูกไว้กับ invitation แล้ว ทุกคำตอบ required ในชุดคำถามของ source/version ต้องครบ ห้าม question_id หรือ option_ids ซ้ำ ขีดจำกัด payload คือ 2 MiB, สูงสุด 50 answers และ 50 option IDs ต่อ answer (และยังตรวจข้อจำกัดของแต่ละคำถาม)

Receipt 201 หลัง commit และ 200 เมื่อ retry สำเร็จเดิม:

```json
{
  "response_id": "55555555-5555-4555-8555-555555555555",
  "client_submission_id": "popup-example-001",
  "status": "stored",
  "received_at": "2026-11-17T05:00:01Z"
}
```

### บันทึก shown/dismissed

`POST /prompt-events`:

```json
{
  "actor_user_id": "account-example-001",
  "invitation_id": "44444444-4444-4444-8444-444444444444",
  "event_id": "event-example-001",
  "event_type": "shown",
  "occurred_at": "2026-11-17T04:59:10Z"
}
```

เปลี่ยน event_type เป็น `dismissed` พร้อม event_id/key ใหม่สำหรับการปิดจริง เวลาของเหตุการณ์ต้องอยู่ในช่วงอายุ invitation แม้ request จะมาถึงภายหลังได้ ระบบคืน 201 หลัง commit หรือ 200 เมื่อซ้ำเหมือนเดิม ไม่ใช่ 202 queue การแสดง/ปิดไม่เพิ่มคำตอบและไม่เริ่ม cooldown

## Flow ของหน้า Feedback — always_on

อ่าน config ด้วย `source=always_on` และตรวจ `channels.always_on` พร้อม `customer_service` สำหรับชื่อ/ไอคอน/คำอธิบายทางเข้า แสดง rating และ Other Questions ที่เปิดใช้ตามลำดับ position ส่ง `/responses` โดยไม่มี invitation หรือ transaction context

ตัวอย่างนี้สมมติว่า rating ไม่บังคับเหตุผล; ถ้า config บังคับ ให้เพิ่ม option_ids ของคะแนน 4 ที่ถูกต้อง:

```json
{
  "client_submission_id": "feedback-example-001",
  "actor_user_id": "account-example-001",
  "survey_version_id": "11111111-1111-4111-8111-111111111111",
  "source": "always_on",
  "submitted_at": "2026-11-17T05:00:00Z",
  "answers": [
    {"question_id": "22222222-2222-4222-8222-222222222222", "rating_value": 4},
    {"question_id": "77777777-7777-4777-8777-777777777777", "option_ids": ["88888888-8888-4888-8888-888888888888"]},
    {"question_id": "66666666-6666-4666-8666-666666666666", "text_value": "อยากให้เพิ่มคำอธิบายบริการ"}
  ]
}
```

ไม่ส่ง rating.text_value ในช่องทางนี้ หากไม่ตอบคำถาม text/choice ที่ optional ให้ละ answer นั้นทั้งหมด; การส่ง text ว่างหรือ choice ว่างยังถือเป็นข้อมูลไม่ถูกต้อง

คำตอบ always_on ไม่ลดโควตาและไม่เริ่ม cooldown ของ BUY_GOLD/SALE_GOLD ข้อเสนอจำกัดหน้า Feedback 3 ครั้งต่อวัน **ยังไม่บังคับใช้** ส่วน anti-abuse rate limit ตาม published rule ยังใช้งานแยกกัน

## Quota, cooldown และ concurrency

นับเฉพาะคำตอบ auto_prompt ที่ commit สำเร็จต่อ `(actor_user_id, transaction_type)` รวมข้าม survey versions สูงสุด 3 คำตอบ หลัง commit แต่ละครั้งพัก type นั้น 30 วันจาก **received_at ของ server** ไม่ใช้ submitted_at ของ client

ตัวอย่าง BUY_GOLD สำเร็จวันที่ 17 พ.ย. 2026 เวลา 05:00:01Z → cooldown ถึง 17 ธ.ค. 2026 เวลา 05:00:01Z; SALE_GOLD ตรวจแยกได้ หลังครบกำหนดให้ตรวจ eligibility ใหม่ ตัวนับยังเป็น 1 ไม่กลับเป็น 0 ถ้าครบ 3 แล้วจะได้ response_limit_reached แม้ครบ 30 วัน

**นโยบายรีเซ็ตและการจัดการหลังครบ 3 คำตอบรอยืนยัน** ระบบใช้ `reset_policy=pending_confirmation` และไม่รีเซ็ตอัตโนมัติ ไม่สร้าง job รีเซ็ตทุก 30 วัน

MySQL transaction ล็อก actor ก่อนตรวจประวัติและบันทึก response/answers/options/idempotency receipt ร่วมกัน จึงป้องกัน concurrent submissions เกิน limit ตัวนับและ cooldown คำนวณจาก response ที่ commit แล้วโดยตรง ไม่มี mutable counter แยกตาราง เมื่อ rollback จะไม่มีคำตอบหรือ quota เพิ่ม การมี invitation หลายใบไม่รับประกันว่าทุกใบจะ submit ผ่าน

ช่วง popup_schedule แยกจาก cooldown: starts_at รวมจุดเริ่มต้น, ends_at ไม่รวมจุดสิ้นสุด, null คือไม่จำกัดด้านนั้น

## Retry และ error handling

- เก็บ submission ID, idempotency key และ payload ไว้จนได้ receipt ที่แน่นอน Timeout, connection loss หรือ 5xx ให้ retry ค่าเดิม แม้ครั้งก่อนอาจ commit แล้ว
- Request ใหม่ใช้ key ใหม่ คำตอบเดิมใช้ key เดิม ทุก operation ควรมี key ของตัวเอง
- key เดิมและ payload ต่างกันได้ 409; การสลับลำดับ object keys ไม่เปลี่ยน hash แต่ลำดับ arrays, ชนิดข้อมูล, whitespace และค่า field มีผล ห้ามเปลี่ยน submitted_at เมื่อ retry
- client_submission_id เดิมภายใน caller/actor เดิม แม้ใช้ key ใหม่ก็ไม่เพิ่มคำตอบ หาก payload เหมือนเดิมคืน 200; ถ้าต่างได้ 409
- 422 ตรวจ fields/message และแก้ข้อมูลหรือรอเงื่อนไขให้ผ่านก่อนเริ่มความพยายามใหม่ ไม่วนส่งอัตโนมัติ
- 429 รอตาม Retry-After หากมี แล้ว retry ตามชนิด operation; ไม่เปลี่ยน submission key เพื่อหลบ limit
- GET response ใช้ caller/actor เดิมเท่านั้น และคืนข้อมูลภายในแบบ Eloquent ที่อาจมี payload_hash/context IDs จึงต้องเลือก fields ก่อนส่งต่อแอป

รูปแบบ error:

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The given data was invalid.",
    "request_id": "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa",
    "fields": {"answers.0.rating_value": ["The rating value must be between 1 and 5."]}
  }
}
```

`fields` มีเฉพาะ validation error ที่ระบุ field; business validation บางรายการมีเพียง message ส่วน `request_id` ตรงกับ header `X-Request-ID` ให้ใช้ในการติดตามปัญหา ไม่พึ่งข้อความภาษาอังกฤษเป็น error code

| HTTP | error.code | การจัดการ |
|---|---|---|
| 401 | UNAUTHENTICATED | ตรวจ token และ X-Service-ID |
| 403 | FORBIDDEN | ตรวจ service scope |
| 404 | NOT_FOUND | ตรวจ IDs, published version, channel และ caller/actor ownership |
| 409 | CONFLICT | ตรวจ key/payload/context/manifest ที่ขัดแย้ง |
| 413 | PAYLOAD_TOO_LARGE | ลด payload ให้ไม่เกิน 2 MiB |
| 422 | VALIDATION_FAILED | ตรวจ fields และ business conditions |
| 429 | RATE_LIMITED | รอก่อน retry |
| 500 / 503 | INTERNAL_ERROR / UNAVAILABLE | retry submission เดิม พร้อม key เดิม |

## Cohort สำหรับรายชื่อลูกค้า

ใช้ `X-Service-ID: user-adapter` และ token ของ adapter แยกจาก app-backend:

1. POST `/cohort-snapshots`: `external_id`, `captured_at`, `expected_count`, `expected_batches`, `identity_type:"account"` รับ snapshot_id สถานะ incomplete
2. POST `/cohort-snapshots/{id}/members`: ส่ง batch_id และ members 1–500 รายการ แต่ละรายการมี actor_user_id, identity_complete และ optional one_id/display_name/phone ห้ามซ้ำบัญชีใน/ข้าม batch
3. POST `/cohort-snapshots/{id}/complete`: ไม่ต้องส่ง body หรือส่ง `{}` เมื่อจำนวน members และ batches ตรง manifest แล้วจึง complete ถ้าไม่ครบได้ 422

ดู JSON examples ใน OpenAPI/Bruno `expected_count=0` กับ `expected_batches=0` complete ได้โดยไม่ส่ง batch Snapshot complete แล้ว immutable; retry batch เดิมยังได้ receipt เดิม หน้า Admin ใช้ snapshot complete ล่าสุดตาม captured_at และเตือนเมื่อมี snapshot ใหม่ที่ยังไม่ครบ การมี routes เหล่านี้ไม่ได้หมายความว่าเชื่อม sync user-service อัตโนมัติแล้ว

## Version และงานที่ยังรอยืนยัน

การแก้คำถาม/เหตุผล/เปิดปิดสร้าง draft และ publish รุ่นใหม่ IDs คำถาม/ตัวเลือกเปลี่ยนตาม version แต่ code เป็นรหัสเชิงตรรกะ เก็บ version และ IDs จาก config ชุดเดียวกันตลอดการกรอก ไม่ผสม IDs รุ่นเก่ากับใหม่

Published version เก่ายังรับคำตอบตามความหมายของรุ่นนั้นได้ภายใต้กฎช่องทางปัจจุบัน จนกว่าจะยืนยัน retirement policy การปิด Other Question ใน version ใหม่ไม่ได้แก้ประวัติหรือยกเลิกแบบฟอร์มรุ่นเก่าที่เปิดค้างอยู่ทันที คำตอบที่เคย commit แล้ว retry ได้ receipt เดิมแม้ปิดช่องทางภายหลัง

ยังต้องยืนยัน: reset หลังครบ 3, retirement ของ published versions, นโยบายธุรกรรม revert, ความถี่ always_on และ integration/auth contract ของ backend เดิม งานนี้ปรับเฉพาะ Feedback backend/Admin ไม่ได้แก้หรือ deploy แอปมือถือและ service เดิม
