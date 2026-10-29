# BAIT Website - คู่มือการติดตั้ง

## ข้อมูลโปรเจกต์
- **ระบบ**: คณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ (BAIT), มหาวิทยาลัยเทคโนโลยีราชมงคลสุวรรณภูมิ
- **Version**: 1.0.0
- **Tech Stack**: PHP 8.2, MariaDB 10.4 / MySQL 5.7+, Bootstrap 5.3 (หลังบ้าน), CSS/JS ของระบบเอง (หน้าเว็บ)

---

## ความต้องการของระบบ (Requirements)

| รายการ | เวอร์ชันขั้นต่ำ |
|--------|----------------|
| PHP | 8.0+ |
| MySQL / MariaDB | 5.7+ / 10.3+ |
| Web Server | Apache / Nginx |
| PHP Extensions | PDO, PDO_MySQL, mbstring, fileinfo, dom, curl (ข่าวไอที RSS) · intl (ไม่บังคับ) |

ระบบไม่ใช้ GD/Imagick — การย่อและครอปรูปทำในเบราว์เซอร์ก่อนอัปโหลด

---

## ขั้นตอนการติดตั้ง

### 1. วางไฟล์บน Server

```bash
# สำหรับ Local (XAMPP/WAMP)
C:\xampp\htdocs\bait\

# สำหรับ Production
/var/www/html/bait/
```

### 2. สร้างฐานข้อมูล

```sql
CREATE DATABASE bait_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

จากนั้น Import ไฟล์ `database/schema.sql` (โครงสร้างตารางทั้งหมดของระบบปัจจุบัน ไม่มีข้อมูลจริง ไม่มีบัญชีผู้ใช้):
```bash
mysql -u root -p bait_db < database/schema.sql
```

หรือผ่าน phpMyAdmin: เลือก Database → Import → เลือกไฟล์ `database/schema.sql`

### 3. ตั้งค่าการเชื่อมต่อ

- **เครื่อง localhost (XAMPP)**: ไม่ต้องแก้อะไร — ใช้ฐาน `bait_db` ผู้ใช้ `root` ไม่มีรหัสผ่าน (ค่าเริ่มต้นของ XAMPP)
- **โฮสต์จริง**: คัดลอก `config/database.host.example.php` เป็น `config/database.host.php` บนโฮสต์ แล้วใส่ค่าให้ครบ 5 ค่า (host, name, user, pass, base)
  ไฟล์นี้มีรหัสผ่าน จึงต้องอยู่บนโฮสต์เท่านั้น และไม่อยู่ใน repository (`.gitignore`)
- อีเมลสำหรับโควตาแปลภาษาอังกฤษอัตโนมัติตั้งไว้แล้วใน `config/database.php` (`MYMEMORY_EMAIL`)
  ถ้าต้องการใช้อีเมลอื่นบนโฮสต์ ใส่ `mymemory_email` ใน `database.host.php`

### 3.1 สร้างบัญชี Super Admin คนแรก

- **นำเข้าฐานข้อมูลจริง (ไฟล์ .sql ที่ส่งมอบ)**: บัญชีผู้ใช้ติดมากับฐานข้อมูลแล้ว ข้ามข้อนี้ได้
- **ติดตั้งใหม่จาก `database/schema.sql`** (ไม่มีบัญชีผู้ใช้): เปิด `https://โดเมน/bait/admin/setup.php`
  (หน้าเข้าสู่ระบบจะมีลิงก์ "ตั้งค่าครั้งแรก" ให้) กรอกชื่อผู้ใช้ ชื่อ-นามสกุล อีเมล และรหัสผ่าน แล้วกด "สร้างบัญชี Super Admin"
  - หน้านี้ใช้ได้ **เฉพาะตอนที่ยังไม่มีบัญชีใดในระบบ** เมื่อสร้างแล้วจะปิดตัวเองถาวร (เปิดอีกครั้งจะขึ้น "ตั้งค่าเรียบร้อยแล้ว")
  - ควรทำทันทีหลังนำเข้า schema.sql เพราะระหว่างที่ยังไม่มีบัญชี ใครก็ตามที่เปิดหน้านี้ได้ก่อนจะเป็นผู้สร้างบัญชี

ระบบมี Super Admin ได้บัญชีเดียว บัญชีอื่นเพิ่มจากเมนู "จัดการผู้ใช้" ในหลังบ้าน (เป็น Sub Admin)

### 3.2 ลืมรหัสผ่าน Super Admin

ในระบบไม่มีปุ่ม "ลืมรหัสผ่าน" ผู้ดูแลเซิร์ฟเวอร์ตั้งรหัสใหม่ได้ดังนี้

1. สร้างค่า hash ของรหัสผ่านใหม่ (บนเครื่องที่มี PHP):
   ```bash
   php -r "echo password_hash('รหัสผ่านใหม่', PASSWORD_BCRYPT, ['cost' => 12]), PHP_EOL;"
   ```
2. phpMyAdmin → SQL (เปลี่ยน `ชื่อผู้ใช้` เป็นของจริง) — `session_version + 1` ทำให้ทุกเครื่องที่ค้างล็อกอินบัญชีนี้ไว้ถูกออกจากระบบ:
   ```sql
   UPDATE users SET password_hash = 'ค่าที่ได้จากข้อ 1', session_version = session_version + 1, is_active = 1
   WHERE username = 'ชื่อผู้ใช้' AND role = 'superadmin';
   ```
3. ถ้าล็อกอินผิดหลายครั้งจนถูกพัก 15 นาที ลบไฟล์ `cache/login_attempts.json` ได้ทันที

### 4. สิทธิ์โฟลเดอร์ (Linux)

```bash
find /var/www/html/bait -type d -exec chmod 755 {} \;
find /var/www/html/bait -type f -exec chmod 644 {} \;
chmod -R 775 /var/www/html/bait/uploads /var/www/html/bait/cache   # PHP ต้องเขียนได้ (ไม่ต้องใช้ 777)
```

`cache/` เก็บเซสชันของหลังบ้าน (`cache/sessions`) ตัวนับล็อกอินผิด และแคชสถิติ — เว็บเปิดอ่านโฟลเดอร์นี้ไม่ได้ (`cache/.htaccess`)

### 5. ค่าเฉพาะของเว็บเซิร์ฟเวอร์

- **Apache + mod_rewrite + mod_headers** (ใช้ `.htaccess`) — บนเซิร์ฟเวอร์ของตัวเอง: `sudo a2enmod rewrite headers && sudo systemctl restart apache2`
  และต้องตั้ง `AllowOverride All` ให้โฟลเดอร์เว็บ
- **ชื่อโฟลเดอร์ `/bait/`**: `.htaccess` มี 2 บรรทัดที่ผูกกับโฟลเดอร์นี้ ถ้าติดตั้งที่อื่นต้องแก้ให้ตรง
  ```apache
  ErrorDocument 404 /bait/404.php      # ติดตั้งที่ราก → /404.php
  RewriteBase /bait/                   # ติดตั้งที่ราก → /
  ```
  และค่า `base` ใน `config/database.host.php` ต้องเป็นโดเมน + โฟลเดอร์เดียวกัน (เช่น `www.example.ac.th/bait`)
- **ขนาดไฟล์อัปโหลด**: หลังบ้านรับวิดีโอได้ถึง 50 MB จึงต้องให้ PHP รับได้ ≥ 60 MB
  - Apache mod_php (เช่น XAMPP): `.htaccess` ตั้งให้แล้ว
  - PHP-FPM / CGI (โฮสต์ส่วนใหญ่): ใช้ไฟล์ `.user.ini` ที่รากเว็บ (มีให้แล้ว มีผลภายใน 5 นาที)
  - ถ้าโฮสต์ไม่อนุญาตทั้งสองแบบ ให้ตั้งในแผงควบคุมโฮสต์: `upload_max_filesize = 60M`, `post_max_size = 65M`
- **Nginx**: ไม่อ่าน `.htaccess` ต้องตั้งกฎเทียบเท่าเอง (ห้ามเข้า `cache/ includes/ config/ layout/ admin/layout/`, ไฟล์ที่ขึ้นต้นด้วยจุด, `*.sql *.ini *.md *.json *.log`,
  ส่ง `/robots.txt` → `robots.php`, `/sitemap.xml` → `sitemap.xml.php`, หน้า 404 → `/bait/404.php`) และตั้ง header `Content-Security-Policy` ให้ `uploads/` ตาม `uploads/.htaccess`
- **robots.txt**: เว็บอยู่ใต้ `/bait/` แต่ search engine อ่าน robots.txt ที่รากโดเมนเท่านั้น
  ถ้าต้องการให้กฎมีผล ให้คัดลอกเนื้อหาจาก `https://โดเมน/bait/robots.txt` ไปไว้ใน robots.txt ที่รากโดเมน

### 6. ตรวจหลังติดตั้ง

1. เปิดหน้าแรก หน้าข่าว หน้าหลักสูตร และสลับภาษา TH/EN
2. เข้าหลังบ้าน → แดชบอร์ด (ถ้ามีคำเตือน "ระบบกันการเดารหัสผ่านปิดอยู่" ให้แก้สิทธิ์โฟลเดอร์ `cache/` ให้ PHP เขียนได้)
3. ลองอัปโหลดรูปข่าว 1 รูป แล้วลบข่าวทดสอบนั้นทิ้ง

---

## เข้าใช้งานระบบ

### Admin Panel
- **URL**: `http://localhost/bait/admin/login.php`
- เข้าสู่ระบบด้วยบัญชีที่สร้างในข้อ 3.1 (ชื่อผู้ใช้และรหัสผ่านไม่เขียนไว้ในไฟล์ใด)

### หน้าเว็บไซต์
- **URL**: `http://localhost/bait/`

---

## โครงสร้างไฟล์ (Folder Structure)

```
bait/
├── admin/                    # หลังบ้าน
│   ├── layout/               # header (เมนู) / footer (สคริปต์) / stat_grid
│   ├── modules/              # 1 โฟลเดอร์ต่อ 1 เมนู: news, categories, it-news, articles, forms, form-categories,
│   │                         #   info-links, programs, tuition, majors, levels, faculty, campus, campus-contacts,
│   │                         #   departments, staff, staff-units, positions, staff-types, org-chart, committee,
│   │                         #   student-counts, staff-counts, banners, popups, partners, home-promo, statistics,
│   │                         #   settings, users, tags
│   ├── assets/               # CSS/JS ของหลังบ้าน (ตัวอย่างรูปที่อัปโหลด, ย่อรูป, ตัวกรอง ฯลฯ)
│   ├── tools/                # เครื่องมือผู้ดูแล (ตรวจไฟล์อัปโหลดที่ไม่มีใครใช้)
│   ├── dashboard.php · login.php · logout.php · 403.php
│   └── setup.php             # ตั้งค่าครั้งแรก: สร้าง Super Admin (ใช้ได้เฉพาะตอนยังไม่มีบัญชี)
├── assets/                   # CSS (tokens, base, components, pages) / JS / รูปของหน้าเว็บ
├── config/
│   ├── database.php          # การตั้งค่าระบบ (ไม่มีรหัสผ่าน)
│   └── database.host.example.php   # ตัวอย่างไฟล์ตั้งค่าของโฮสต์
├── includes/                 # Database, Auth, helpers, components, lang_en (พจนานุกรมแปล), xlsx
├── layout/                   # header / footer / icons ของหน้าเว็บ
├── uploads/                  # ไฟล์ที่อัปโหลด (รูปและเอกสารของเว็บไซต์ปัจจุบัน — อัปขึ้นโฮสต์ทั้งโฟลเดอร์)
├── cache/                    # แคชของระบบ (ไม่อยู่ใน repository)
├── database/schema.sql       # SQL Schema (โครงสร้างตาราง ไม่มีข้อมูลจริง)
├── .htaccess · .user.ini     # ค่า Apache / PHP (ขนาดอัปโหลด, header ความปลอดภัย, กันไฟล์สำคัญ)
└── *.php                     # หน้าเว็บ: index, news, news-detail, it-news, articles, programs, program-detail, majors,
                              #   tuition, staff, about, campuses, campus-detail, contact, forms, info, statistics,
                              #   statistics-staff, search, privacy, 404, sitemap.xml, robots
```

---

## ระบบแปลภาษา

หน้าเว็บภาษาอังกฤษแปลจากภาษาไทยดังนี้
1. คำที่ใช้ประจำ (เมนู ตำแหน่ง ป้ายต่าง ๆ) มาจากพจนานุกรม `includes/lang_en.php` — แก้คำแปลที่นี่ได้ทันที
2. ข้อความจากฐานข้อมูล แปลอัตโนมัติผ่าน **MyMemory** (api.mymemory.translated.net ฟรี ไม่ต้องใช้ API key) และเก็บผลไว้ในตาราง `translation_cache` แต่ละข้อความแปลครั้งเดียว
3. ถ้าบริการแปลล่มหรือโควตาเต็ม หน้าเว็บแสดงภาษาไทยไปก่อน แล้วแปลในการเปิดครั้งถัดไป

โควตา: ระบุอีเมลไว้แล้ว 50,000 คำ/วัน (ไม่ระบุอีเมล 5,000 คำ/วัน) — ดูข้อ 3 การตั้งค่า

---

## สำรองและกู้คืนข้อมูล

ข้อมูลของเว็บมี 2 ส่วน ต้องสำรอง **ทั้งคู่ในวันเดียวกัน** (ฐานข้อมูลอ้างชื่อไฟล์ใน uploads)

| ส่วน | วิธีสำรอง | วิธีกู้คืน |
|------|----------|-----------|
| ฐานข้อมูล | phpMyAdmin → เลือกฐาน → Export (Quick, SQL) หรือ `mysqldump --single-transaction -u USER -p DBNAME > bait_YYYYMMDD.sql` | สร้างฐานว่าง (utf8mb4) → Import ไฟล์ .sql หรือ `mysql -u USER -p DBNAME < bait_YYYYMMDD.sql` |
| ไฟล์อัปโหลด | ดาวน์โหลดโฟลเดอร์ `uploads/` ทั้งโฟลเดอร์ (FTP / File Manager ของโฮสต์) | อัปโหลดกลับไปที่ `uploads/` เดิม |

- แนะนำสำรองอย่างน้อยสัปดาห์ละครั้ง และทุกครั้งก่อนรัน SQL / อัปเดตโค้ด
- ไฟล์สำรองมีข้อมูลบัญชีผู้ใช้ (hash รหัสผ่าน) และอีเมลบุคลากร — เก็บในที่ปลอดภัย ห้ามวางไว้ในโฟลเดอร์เว็บ
- ไม่ต้องสำรอง `cache/` (สร้างใหม่เองได้)

## Log ที่ควรดู

- ข้อผิดพลาดของ PHP: error log ของโฮสต์ (แผงควบคุม → Error Logs) หรือ `apache/logs/error.log` ใน XAMPP — ข้อความของระบบขึ้นต้นด้วย `BAIT:`
- ความพยายามล็อกอินผิด: `cache/login_attempts.json` (เก็บ 15 นาที)
- หน้าเว็บไม่แสดงรายละเอียด error ให้ผู้เข้าชม (`display_errors Off`) ต้องดูจาก log เท่านั้น

---

## สิทธิ์ผู้ใช้งาน

| สิทธิ์ | รายละเอียด |
|--------|-----------|
| **superadmin** | เข้าถึงได้ทุกระบบ รวมถึงจัดการผู้ใช้ (มีได้บัญชีเดียว) |
| **subadmin** | ทุกเมนูยกเว้น "จัดการผู้ใช้" — รวมถึงตั้งค่าเว็บไซต์ โหมดไว้อาลัย และลบข้อมูล (ตามนโยบายของคณะ) |

---

## Database Tables (40 ตาราง)

| ตาราง | คำอธิบาย |
|-------|---------|
| users | ผู้ใช้งานระบบ Admin |
| site_settings | การตั้งค่าเว็บไซต์ (ข้อมูลทั่วไป SEO แถบข้อความวิ่ง ไว้อาลัย Footer พื้นหลังเกี่ยวกับคณะ) |
| categories | ประเภทข่าวสาร |
| news / news_sdgs / tags | ข่าวสาร / เป้าหมาย SDGs ของข่าว / คลังแท็ก |
| it_news | ข่าวไอที (RSS) |
| articles | บทความ |
| majors / education_levels | สาขาวิชา / ระดับการศึกษา |
| programs / program_campuses | หลักสูตร / ศูนย์ที่เปิดสอน |
| tuition_fees / tuition_page / tuition_options | ค่าธรรมเนียมการศึกษา / ตั้งค่าหน้า / ตัวเลือกในฟอร์ม |
| campuses / campus_contacts | ศูนย์พื้นที่ / ช่องทางติดต่อ |
| faculty / about_sections / about_section_items / highlights | ข้อมูลคณะ / หัวข้อหน้าเกี่ยวกับคณะ / รายการในหัวข้อ / จุดเด่นของคณะ |
| staff / staff_types / positions / departments / staff_units | บุคลากร / ประเภท / ตำแหน่ง / หน่วยงาน / งาน (สายสนับสนุน) |
| major_campus_heads / committee_members | หัวหน้าสาขาแต่ละศูนย์ / คณะกรรมการประจำคณะ |
| student_counts | จำนวนนักศึกษา |
| staff_counts | จำนวนบุคลากรแบบกรอกเอง (เลิกใช้แล้ว ระบบนับจากตาราง staff) |
| form_categories / forms | หมวดหมู่แบบฟอร์ม / แบบฟอร์มดาวน์โหลด |
| info_links | ลิงก์ระบบสารสนเทศ |
| banners / popup_ads / partners / home_promo | แบนเนอร์ / ป๊อปอัพ / โลโก้พันธมิตร / วิดีโอโปรโมทหน้าแรก |
| page_views / page_referrers | สถิติการเข้าชม / Backlink |
| translation_cache | แคชการแปลภาษา |

---

## Support

- **Email**: admin@bait.rmutsb.ac.th
- **MyMemory (แปลภาษา)**: https://mymemory.translated.net/
- **Bootstrap 5 Docs**: https://getbootstrap.com/
