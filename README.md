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

`database/schema.sql` ไม่มีบัญชีผู้ใช้ สร้างบัญชีแรกด้วยคำสั่ง (เปลี่ยนชื่อผู้ใช้ อีเมล และรหัสผ่านเป็นของคุณเอง):

```bash
php -r "echo password_hash('รหัสผ่านของคุณ', PASSWORD_BCRYPT, ['cost' => 12]), PHP_EOL;"
```

แล้วนำค่าที่ได้ไปใส่ใน SQL นี้ (phpMyAdmin → SQL):

```sql
INSERT INTO users (username, password_hash, full_name, email, role, is_active)
VALUES ('ชื่อผู้ใช้', 'ค่าที่ได้จากคำสั่งข้างบน', 'ชื่อ-นามสกุล', 'อีเมล', 'superadmin', 1);
```

ระบบมี Super Admin ได้บัญชีเดียว บัญชีอื่นเพิ่มจากเมนู "จัดการผู้ใช้" ในหลังบ้าน (เป็น Sub Admin)

### 4. ตั้งค่า Permissions (Linux/Mac)

```bash
chmod -R 755 /var/www/html/bait/
chmod -R 777 /var/www/html/bait/uploads/
chmod -R 777 /var/www/html/bait/cache/
```

### 5. ตั้งค่า Apache (ถ้าใช้ mod_rewrite)

ตรวจสอบว่าเปิดใช้ `mod_rewrite`:
```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

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
├── assets/                   # CSS (tokens, base, components, pages) / JS / รูปของหน้าเว็บ
├── config/
│   ├── database.php          # การตั้งค่าระบบ (ไม่มีรหัสผ่าน)
│   └── database.host.example.php   # ตัวอย่างไฟล์ตั้งค่าของโฮสต์
├── includes/                 # Database, Auth, helpers, components, lang_en (พจนานุกรมแปล), xlsx
├── layout/                   # header / footer / icons ของหน้าเว็บ
├── uploads/                  # ไฟล์ที่อัปโหลด (รูปและเอกสารของเว็บไซต์ปัจจุบัน — อัปขึ้นโฮสต์ทั้งโฟลเดอร์)
├── cache/                    # แคชของระบบ (ไม่อยู่ใน repository)
├── database/schema.sql       # SQL Schema (โครงสร้างตาราง ไม่มีข้อมูลจริง)
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

## สิทธิ์ผู้ใช้งาน

| สิทธิ์ | รายละเอียด |
|--------|-----------|
| **superadmin** | เข้าถึงได้ทุกระบบ รวมถึงจัดการผู้ใช้ |
| **subadmin** | จัดการเนื้อหาทั่วไป ไม่สามารถจัดการผู้ใช้ได้ |

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
