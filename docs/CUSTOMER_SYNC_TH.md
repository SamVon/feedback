# การซิงก์ลูกค้าจาก User Service เข้า Staff Web

คำสั่ง `feedback:sync-customers` ใช้ adapter ฝั่งเซิร์ฟเวอร์ อ่าน User Service และส่งรายชื่อผ่าน Cohort API เดิม หน้า Customers ใช้ complete snapshot ล่าสุดและรวมคำตอบด้วย `actor_user_id`

## ตั้งค่า staging

ใส่ค่าใน environment ของเครื่องที่รัน adapter

```dotenv
FEEDBACK_CUSTOMER_SOURCE_ENVIRONMENT=staging
USER_SERVICE_ENVIRONMENT=staging
USER_SERVICE_BASE_URL=https://USER_TEST_HOST
USER_SERVICE_READ_TOKEN=AUTHORIZED_READ_TOKEN
FEEDBACK_SYNC_BASE_URL=https://FEEDBACK_INTERNAL_HOST
FEEDBACK_COHORT_TOKEN=COHORT_SERVICE_TOKEN
```

ให้ผู้ดูแล User Service ออก Access Token สำหรับอ่านรายชื่อลูกค้าด้วยสิทธิ์ที่ API เดิมรองรับ ใส่ token ลง `USER_SERVICE_READ_TOKEN` โดยตรง ไม่ใช้ JWT signing key การเรียก `GET /user-service/api/v1/user/` ใช้ `role=CUSTOMER`, `limit=500`, `skip` และรูปแบบเรียงที่ API เดิมรองรับ ผลตอบกลับต้องมี `error=false`, `total` และ `data[]` แต่ละบัญชีมี `id` และ `role=CUSTOMER` วิธีนี้ไม่ต้องแก้หรือ deploy User Service

ค่า `FEEDBACK_CUSTOMER_SOURCE_ENVIRONMENT` และ `USER_SERVICE_ENVIRONMENT` ต้องตรงกัน เป็นการประกาศ environment โดยผู้ดูแล ให้ตรวจ URL และตัวอย่าง User ID กับ JWT ของลูกค้าทดสอบร่วมด้วยก่อนรันจริง

ฝั่ง Feedback ตั้ง `FEEDBACK_COHORT_TOKEN_SHA256` เป็น SHA-256 ของ `FEEDBACK_COHORT_TOKEN` ที่ adapter ใช้ ผ่านช่องทางจัดการ secrets ของเซิร์ฟเวอร์ คำขอ Cohort ใช้ `X-Service-ID: user-adapter` และ scope `cohort.write` ใช้ Internal URL ที่ adapter เข้าถึงได้ ตัวส่งต่อ Cloudflare ของ App API เปิดเฉพาะ `/api/v1/feedback` จึงต้องใช้ Internal URL แยกสำหรับงานนี้

## ตรวจสาเหตุก่อนซิงก์

```bash
php artisan feedback:sync-customers --check
```

คำสั่งนี้ตรวจค่าที่จำเป็นและ environment โดยไม่เรียก API หรือสร้าง snapshot เมื่อใช้ export ให้เพิ่ม `--snapshot-file=/private/path/verified-users-export.json` ค่า token จะไม่ถูกแสดง เมื่อแจ้ง Missing configuration ให้เพิ่มตัวแปรชื่อนั้นใน `.env` ของ adapter และหากมี config cache ให้รัน `php artisan config:clear` ก่อนตรวจใหม่

`USER_SERVICE_READ_TOKEN` ใช้ credential ฝั่งเซิร์ฟเวอร์ที่ได้รับสิทธิ์อ่านรายชื่อลูกค้า ส่วน `FEEDBACK_COHORT_TOKEN` ใช้ service token สำหรับเขียน Cohort และต้องตรงกับ hash ที่ Feedback ปลายทางตั้งไว้ ถ้า adapter อยู่บน Mac เครื่องเดียวกับ Feedback ใช้ `FEEDBACK_SYNC_BASE_URL=http://127.0.0.1:8000` ได้เมื่อบริการนั้นใช้ฐานข้อมูลเป้าหมายที่ต้องการ

ข้อความผิดพลาดระบุช่วงที่ล้มเหลว เช่น User Service HTTP 401/403, Cohort API HTTP 401/403, การเชื่อมต่อผิดพลาด หรือชื่อฟิลด์ที่ไม่ผ่าน validation โดยตัด response body และ credentials ออกจากข้อความ เมื่อแก้สาเหตุแล้วใช้ Run ID เดิมในการ resume

## อ่าน API รายชื่อปัจจุบัน

```bash
php artisan feedback:sync-customers
```

คำสั่งอ่านทุกหน้า โดยเลื่อน `skip` ตามจำนวนแถวที่ได้รับ ไม่ใช้จำนวนบัญชีไม่ซ้ำเป็น offset รวมข้อมูลด้วย User ID เพื่อไม่สร้างลูกค้าซ้ำ ถ้าจำนวนบัญชีไม่ซ้ำยังไม่ถึง `total` จะอ่านเพิ่มด้วย `updated_at` และ `last_kyc_updated_at` หลังรอบแรกที่ใช้ `created_at` รวมสูงสุดสามรอบ และหยุดเมื่อจำนวนไม่ซ้ำถึง `total`

ทุกหน้าต้องรายงาน `total` เดิม ถ้าจำนวนเปลี่ยน มีหน้าว่างก่อนถึงปลายรายการ หรือมีบัญชีมากกว่าที่ต้นทางรายงาน จะหยุดก่อนส่ง Cohort ส่วนการ map ส่งเฉพาะ `id → actor_user_id`, ชื่อ และ `identity_complete` ข้อมูล phone, KYC, รหัสผ่าน และบัญชีธนาคารถูกตัดออก คำสั่งแสดงความคืบหน้าเป็นจำนวนบัญชีและ batch โดยไม่พิมพ์โปรไฟล์หรือ token

API ปัจจุบันใช้ offset และเรียงด้วย timestamp ซึ่งยังยืนยัน snapshot แบบ atomic ไม่ได้ แม้อ่านครบ total จึงส่งรายชื่อเข้า snapshot สถานะ incomplete และแสดงคำเตือน หน้า Customers ยังใช้ complete snapshot ก่อนหน้า ถ้าไม่มี complete เลยจะแสดง partial snapshot พร้อมสถานะข้อมูลไม่ครบ

## ดูข้อมูลจริงที่อ่านได้โดยไม่แก้ User Service

ถ้าต้นทางมีข้อมูลบางแถวที่ decode ไม่ได้ การซิงก์ปกติจะหยุด ใช้โหมด preview แบบระบุชัดเจนได้:

```bash
php artisan feedback:sync-customers --allow-partial
```

สำหรับการทดสอบรายชื่อขนาดประมาณ 50,000 บัญชีบน Mac สามารถกำหนดหน่วยความจำเฉพาะคำสั่งได้ โดยไม่เปลี่ยน PHP ของเว็บ:

```bash
php -d memory_limit=512M artisan feedback:sync-customers --allow-partial
```

โหมดนี้แบ่งคำขอที่พบ HTTP 500 พร้อมข้อผิดพลาด decode ออกเป็นช่วงเล็กลงจนแยกแถวที่อ่านไม่ได้ แล้วข้ามเฉพาะแถวนั้น จำกัดจำนวนการกู้คืนและรอบอ่าน ไม่ข้าม HTTP 401/403, service outage, การเปลี่ยน total หรือข้อมูลที่ไม่ผ่าน validation ถ้ายังมีบัญชีขาดหลังสามรอบ จะแสดงจำนวนที่อ่านได้เทียบกับ `total` และเก็บชุดนำเข้าเป็น incomplete เท่านั้น หากอ่านไม่ได้เลยจะไม่สร้างชุดว่างแทนข้อมูลเดิม

ในหน้า Customers เลือก **Latest imported list (preview)** / **ລາຍຊື່ທີ່ຊິງຫຼ້າສຸດ (ເບິ່ງຕົວຢ່າງ)** หรือเปิด:

```text
http://127.0.0.1:8000/admin/customers?customer_source=latest_import
```

จะเห็นโปรไฟล์ที่ User Service ส่งมาและจำนวนคำตอบจริงตาม User ID พร้อมคำเตือนว่าไม่ใช่ snapshot ที่รับรองความครบ สถานะการตอบยังเป็นข้อมูลไม่ครบตามกติกาเดิม รายชื่อหลักแบบ verified ยังคงใช้ชุด complete ล่าสุด การเปิด preview ไม่เปลี่ยนสถานะของ snapshot และไม่เปลี่ยน denominator ของรายงานเป็นจำนวนที่ยืนยันแล้ว

หน้า preview เลือกเฉพาะชุดที่ส่งสมาชิกและ batch ครบ manifest ของชุดนำเข้านั้น หากซิงก์ล้มเหลวระหว่างส่ง batch จะใช้ชุดที่ส่งครบก่อนหน้า ในกรณี `--allow-partial` manifest หมายถึงจำนวนบัญชีที่อ่านได้ ไม่ใช่ `total` ของต้นทาง จำนวนที่ขาดระบุในผลคำสั่งและ encrypted journal

## Complete จาก export ที่ต้นทางรับรอง

ให้ User Service สร้าง export บน snapshot ที่คงที่ โดยใช้การอ่านที่สอดคล้องกันของ MongoDB ตามเวอร์ชันและ topology ที่ใช้งาน หรือ materialize รายการส่งออกภายในบริการต้นทาง จากนั้นส่ง JSON ผ่านช่องทางไฟล์ที่เชื่อถือได้ รูปแบบต่อไปนี้เป็น contract สำหรับ export ใหม่ที่ต้องเพิ่มฝั่งต้นทาง

```json
{
  "snapshot_id": "users-export-staging-20261124-001",
  "environment": "staging",
  "captured_at": "2026-11-24T07:00:00Z",
  "complete": true,
  "total": 2,
  "data": [
    {"id": "customer-account-a", "role": "CUSTOMER", "first_name": "Customer", "last_name": "A"},
    {"id": "customer-account-b", "role": "CUSTOMER"}
  ]
}
```

`complete=true` เป็นคำรับรองของต้นทาง ผู้ปฏิบัติงานต้องใช้ไฟล์จาก exporter ที่ตรวจสอบแล้ว การเปลี่ยน flag ในผล offset pagination ไม่ได้ทำให้ข้อมูลเป็น atomic snapshot

```bash
php artisan feedback:sync-customers --snapshot-file=/private/path/verified-users-export.json
```

คำสั่งสร้าง manifest ส่ง batch ละไม่เกิน 500 ราย แล้ว complete เมื่อครบ รวมกรณีรายชื่อว่างที่ยืนยันแล้วด้วย manifest จำนวนศูนย์

## Retry และการจัดเก็บ

จด Run ID ที่คำสั่งแสดง แล้วรันซ้ำด้วยค่าเดิมเมื่อขาดการเชื่อมต่อ

```bash
php artisan feedback:sync-customers --run=UUID_FROM_PREVIOUS_RUN
```

ถ้ารอบเดิมใช้ partial preview ให้ระบุโหมดเดิมด้วย:

```bash
php -d memory_limit=512M artisan feedback:sync-customers --run=UUID_FROM_PREVIOUS_RUN --allow-partial
```

adapter เก็บ manifest เข้ารหัสใน private local storage `customer-sync/<run>.enc` และข้อมูลขั้นต่ำที่ map แล้วแยกเป็นไฟล์เข้ารหัสชุดละไม่เกิน 500 รายที่ `customer-sync/<run>/batch-<index>.enc` โดยใช้ APP_KEY เดิม เขียน manifest หลังเก็บ batch ครบ แล้วใช้ batch IDs, keys, timestamps และ payload เดิมตอน retry ไม่ต้องเข้ารหัสรายชื่อทั้งชุดในหน่วยความจำครั้งเดียว การซิงก์สำเร็จแล้วรันซ้ำจะไม่เพิ่มลูกค้าซ้ำ ใช้ Run ID ใหม่เมื่อต้องอ่านข้อมูลต้นทางชุดใหม่ การ resume ใช้ journal ที่ตรึงไว้และตรวจ environment/URL เดิม ยังรองรับ journal รูปแบบเดิมที่รวม members ในไฟล์เดียว

ระหว่างอ่านต้นทาง adapter เก็บแต่ละหน้าที่ map แล้วเป็นไฟล์เข้ารหัสใน `customer-sync/<run>/reading/` พร้อม checkpoint ของ pass, offset, total และ environment หากเครือข่ายขาด ใช้ Run ID และโหมด partial เดิมเพื่ออ่านต่อจากหน้าล่าสุด ตรวจ total ใหม่ทุกหน้าและปฏิเสธการ resume ข้ามต้นทาง เมื่อเขียน manifest และ batch สำหรับนำเข้าครบแล้ว จะลบไฟล์ checkpoint ระหว่างอ่าน และใช้ batch ที่ตรึงไว้สำหรับ retry ต่อไป

การเชื่อมต่อขาดหรือ HTTP 5xx ที่ไม่ใช่ข้อผิดพลาด decode จะลองใหม่แบบจำกัดจำนวน HTTP 401/403 จะหยุดเพื่อให้เปลี่ยน Access Token โดยไม่พิมพ์ token หรือข้อมูลส่วนตัวจากข้อความต้นทาง

รัน adapter บน sync runner เดียว ใช้ cache lock กันงานซ้อน และคง private storage กับ APP_KEY ระหว่าง deploy จำกัดสิทธิ์เข้าถึง journal และกำหนด retention หลังงานสำเร็จตามแนวทางของทีม

## หน้า Customers

รายชื่อหลักมาจาก complete snapshot ล่าสุด ส่วนตัวกรองช่วงวันที่ แบบสอบถาม และ source ใช้กับคำตอบที่บันทึกแล้ว มีตัวกรองสถานะ ทั้งหมด ตอบแล้ว ยังไม่ตอบ และข้อมูลไม่ครบ ลูกค้าที่ยังไม่มีคำตอบแสดงจำนวนศูนย์ เวลาเป็นขีด ชื่อแสดงตามสิทธิ์ `customer_identity.read` ประวัติคำตอบใช้ `feedback.read` การเปิดหน้าใช้ `customers.read`

เมื่อเปลี่ยนช่วงวันที่ จำนวนและเวลาตอบล่าสุดคำนวณใหม่ รายชื่อไม่มี One ID ใช้งานได้ผ่าน User ID เดิม การรวมคำตอบกับ invitations ทำใน query รวมก่อน pagination

## การตรวจรับ

รันบนฐานทดสอบที่กำหนดใน phpunit.xml

```bash
vendor/bin/phpunit tests/Feature/CustomerSyncTest.php --filter=test_customers_
```

ทดสอบการส่ง snapshot, timeout/retry, ตัดข้อมูลส่วนตัวส่วนเกิน, pagination ที่ incomplete, environment mismatch, รายชื่อซ้ำ, ลูกค้ามีและไม่มีคำตอบ, ช่วงวันที่ และการปิดข้อมูลตามสิทธิ์

การเชื่อมต่อ Auth/User Test จริงต้องมี URL และ Access Token อ่านรายชื่อ ส่วน verified export จำเป็นเมื่อต้องยืนยัน complete snapshot การทดสอบที่ใช้ HTTP fake ยืนยันฝั่ง adapter/Filament โดยรัน Cohorts จริงบน MySQL test database

ผลตรวจข้อมูลจริงบน Mac วันที่ 24 พฤศจิกายน 2026: Run ID `0af5f065-8c69-45bb-8d8c-d0c68d673180` ใช้ API เดิมของ User Test และ `--allow-partial` นำเข้า 51,423 จาก 51,424 บัญชี จำนวน 103 batch เป็น snapshot `01a20e08-535e-7011-9521-be75bb1873d1` สถานะ incomplete เหลือหนึ่งบัญชีที่ API ต้นทาง decode ไม่ได้ ไม่แก้โค้ดหรือ deploy User Service

ตรวจ User ID `6ae800b07f2988fd75442da5` พบโปรไฟล์พร้อมชื่อจริงและ Feedback สองคำตอบ หน้า Customers แบบ latest_import ตอบ HTTP 200 และเรนเดอร์ชื่อที่นำเข้า พร้อมคำเตือน preview รายชื่อหลักยังคงใช้ complete snapshot เดิม

การทดสอบ Customers และ CustomerSync ที่เกี่ยวข้องผ่าน 27 รายการ รวม 324 assertions ครอบคลุม retry/checkpoint, batch journal, ข้อมูลซ้ำ, แถวเสีย, สิทธิ์, วันที่, ประวัติ และสถานะ incomplete ใน Excel
