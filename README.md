# Student Grade Portal (Senior High School)

A school portal for Grade 11 and 12 learners, their parents, subject teachers and school staff:

- **Subject teachers** keep a DepEd **class record** (Written Works, Performance Tasks, Quarterly Assessment), upload or type quarterly grades, take **class attendance by scanning learners' QR ID cards**, and post class materials.
- **Parents** have their own accounts. They see each child's attendance in every class, the report card, school news and the calendar. They can send **excuse letters**, **message teachers** and **request documents**. **Notifications** appear inside the portal only (no email, no SMS).
- **Learners** sign in with their **LRN** (by default). They see their home page, report card, class schedule, subjects with materials and their scores, and can request documents.
- **Advisers** see their section's attendance, review excuse letters, rate observed values and print SF9 report cards.
- The **registrar (admin)** sets up the school year, sections, subjects, staff, parents and learners; prints QR IDs, DepEd forms and certificates; and handles grade deadlines, change requests, the honor roll, the year-end rollover and backups.
- The **principal** sees school reports and all records, and approves grade changes. A **guidance counselor** sees learner profiles, at-risk learners and excuse letters.

Each school year has **two semesters**. A subject is taught in one of them: 1st-semester subjects are graded in **Quarters 1–2**, 2nd-semester subjects in **Quarters 3–4**. A subject's final grade is the average of its two quarters, and each semester has its own general average.

Built for XAMPP: PHP 8.2, MariaDB, no framework. Libraries (via Composer): PhpSpreadsheet for Excel files, chillerlan/php-qrcode for QR codes. Bootstrap, Bootstrap Icons, the font and the camera scanner (html5-qrcode) are saved in `public/assets/vendor`, so the portal works without internet.

## Setup

1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. In this folder, run:
   ```
   composer install
   php database/install.php --seed
   ```
   `--seed` adds demo data. Leave it out for a real install: you get an empty database and an `admin` account with a random password, shown on screen.
3. Open http://localhost/student-portal/

**Updating an existing installation:** after copying in new code, run `php database/migrate.php`. It adds new tables and columns and **keeps all data**. You can run it any number of times. Until it has been run, the portal shows a page asking for it. (Never use `install.php --fresh` on a database you want to keep: it deletes everything.)

Demo accounts (`--seed` only):

| Who | Sign in with |
|---|---|
| Registrar (admin) | `admin` / `admin123` at `/staff/login.php` |
| Teacher, math and science; adviser of Grade 12 STEM – Einstein | `msantos` / `teacher123` |
| Teacher, language, social science and PE; adviser of Grade 11 STEM – Newton | `jreyes` / `teacher123` |
| Principal | `principal` / `principal123` |
| Guidance counselor | `guidance` / `guidance123` |
| Parent: Maria Dela Cruz, mother of Juan | `09171234567` / `parent123` at `/parent/login.php` |
| Parent: Ramon Aquino, father of Rafael | `ramon.aquino@example.com` / `parent123` |
| Learner, Grade 12 STEM | LRN `100000260001` |
| Learner, Grade 11 STEM | LRN `100000260011` |

The demo includes two weeks of class attendance, a timetable, announcements, calendar events, grade deadlines, a Quarter 2 class record for General Physics 1 and General Mathematics, class posts, a parent–teacher conversation and a document request. To add the newer demo content to a database installed earlier with `--seed`, run `php database/demo-extras.php` (it keeps other data, but rebuilds the demo timetable and calendar events).

The demo subjects are a sample K to 12 STEM load. Replace them with your school's subjects under Setup → Subjects.

## How it works

### Registrar (admin)

Do these in order; the dashboard has a checklist.
1. **Setup → Settings**: school name, School ID, division, region, address, principal's name (printed on forms and certificates), how learners sign in, the current semester, when a scan counts as Late, and how many absences make a learner "at risk".
2. **Setup → School years**: add one, e.g. `2026-2027`, and make it active.
3. **Setup → Subjects**: each subject with its type (core, applied or specialized). Optionally set its **class record weights**; blank means the DepEd default (core 25/50/25; applied and academic-track specialized 25/45/30). Use 20/60/20 for TVL, Sports, and Arts and Design specialized subjects.
4. **Setup → Staff accounts**: teachers, admins, the principal and guidance counselors, each with a temporary password they must change at first sign-in.
5. **Sections**: each section with its grade level and strand. Open it to add its subjects (semester and teacher for each) and build the **timetable** (the portal blocks times that clash for the teacher or the section).
6. **Learners**: add one at a time, or import from the Excel template. Each learner's page has the profile (photo, address, family, status), QR card reissue, a new parent link code, linked parents, SF10 and certificates.
7. On each section's page, print **QR ID cards** (10 per A4 page) and **parent link slips**.
8. **Records**:
   - *Grading periods & changes*: set each quarter's deadline. After it, the quarter is **locked**; teachers send a **grade change request** that the registrar or principal approves. *Grade history* shows every change and who made it.
   - *DepEd forms*: SF1 (school register) and SF2 (daily attendance, per month) as Excel; SF5 (promotion report); SF9 report cards for a whole section; SF10 permanent records.
   - *Honor roll*: With Honors / High / Highest Honors per semester or for the year (DepEd Order 36, s. 2016).
   - *Document requests*: requests from learners and parents. Change the status (Received → Being prepared → Ready for pick-up → Released, or Not approved with a note); parents get a notification. Print certificates of enrollment and good moral character, SF9 or SF10 from there.
   - *Year-end rollover*: create next year's school year and sections (copying subjects), then promote Grade 11 learners, retain those who failed, and mark Grade 12 learners as graduated.
   - *Reports*: see below.
9. **News**: announcements for everyone, families or staff (parents are notified), and calendar events (holidays, exams, meetings, deadlines; a no-class day warns teachers taking attendance).
10. **Setup → Backup**: see *Backups* below.

### Subject teacher

- **My classes** lists each subject and section assigned to you. Each class has tabs:
  - **Grades**: download the Excel template for a quarter, fill it in and upload it (with a preview, nothing is saved until you confirm), or type grades into the table. **Publish** a quarter when it is final; parents are notified. After the deadline the quarter is locked and changes go through a change request.
  - **Class record**: add Written Works, Performance Tasks and the Quarterly Assessment with their highest possible scores, type the scores (totals, percentage and weighted scores, the initial grade and the transmuted quarterly grade update as you type), then **Use computed grades** to copy them to the grade sheet. An item nobody has a score in yet (e.g. an exam not given yet) is not counted.
  - **Attendance**: **Take attendance**, then scan each learner's QR card with the phone or laptop camera, or a USB QR scanner. A scan more than 15 minutes after the start is **Late** (parents are notified). Mark learners without a card by hand. **Finish attendance** at the end of class: everyone not marked becomes **Absent** (parents are notified), except learners with an approved excuse letter, who become **Excused**. An unfinished class is finished automatically the next day. Marks can be corrected later. There is a monthly Excel sheet per class.
  - **Materials**: post reminders, handouts and modules (PDF, Word, PowerPoint, Excel, images; up to 10 MB). Learners in the section see them under Subjects.
  - **Schedule**: the class's meeting days, times and room.
- **My schedule**: your weekly timetable.
- **At-risk learners**: learners with a grade below 75 or too many absences in your classes, with follow-up notes.
- **Messages**: conversations with parents of your learners.

### Adviser

- **My advisory**: every learner's absences in every subject of the section, linked parents, SF9 report cards and observed values.
- **Excuse letters**: approve or reject letters from parents. Approved letters turn those days' absences into Excused, and the parent is notified.
- **Observed values** (SF9): rate the 7 behavior statements (AO / SO / RO / NO) per quarter.

### Principal and guidance counselor

- **Principal**: Reports (start page), all records, grade change approvals, honor roll, forms, document requests, announcements, learner profiles, at-risk learners and excuse letters.
- **Guidance counselor**: learner profiles (details, family, attendance, report card, excuse letters and follow-up notes), at-risk learners, excuse letters and messages.

**Reports** show, for a school year and quarter: enrollment, attendance rate, failing grades, learners needing follow-up, the grade distribution by DepEd descriptor, attendance by section, grade submission per teacher (fully graded / published), and results per subject (average and pass rate). The page prints cleanly.

### Parent

- Create an account at `/parent/register.php` with your name, a mobile number or email (only used to sign in; nothing is sent to it), a password, and the learner's **LRN and link code** from the slip. Add siblings with **Add a child**.
- The dashboard shows each child's classes today (Present, Late at 9:21 AM, Absent, or still in class), this month's totals, announcements and upcoming events.
- Each child's page has attendance by month (arrival times and teacher notes) and the report card. Confirm you have seen each semester's report card online instead of signing the paper copy; the adviser sees it on the SF9.
- **Notifications**: absent, late, a mark changed, grades released, excuse letter decisions, document request updates and announcements.
- **Messages**: write to your child's adviser or subject teachers. **Excuse letters**: tell the adviser why your child was absent, with an optional photo of a medical certificate. **Requests**: ask for a certificate or a copy of school records.

### Learner

Sign in at the home page. The learner home shows today's classes and attendance, this month's totals, news and the calendar. **Report card** (one table per semester, like the SF9), **Schedule**, **Subjects** (materials from teachers and **My scores** per subject), and **Requests**.

**How learners sign in** (Setup → Settings): LRN only (default), LRN + birthdate, or LRN + password (each learner activates their account once with their birthdate; the registrar can reset it).

## Installable app and dark mode

- The portal can be **installed like an app** on phones and computers (Chrome or Edge: *Install app* in the footer, or the browser menu → *Install*). It opens full screen with the school crest. Only styles, scripts and icons are kept on the device; pages with grades or names always come from the server, and an offline page is shown without a connection. Installing needs `https://` (or `http://localhost` on the server itself).
- **Dark mode**: the moon/sun button in the top bar. The choice is remembered on that device; by default it follows the device setting. Printing always uses light colours.

## Backups

**Setup → Backup** (admin only):
- **Download** the database as a `.sql` file, or **save a copy on the server** (`storage/backups`). Download the uploaded files (excuse letters, materials, photos) as a `.zip`.
- **Restore** the database from a backup file made by the portal. It replaces everything in the database, so it asks for your password and the word RESTORE, and saves a copy of the current database first.
- Keep copies off the server (USB drive or cloud storage), at least weekly and before every year-end rollover. A backup from an older version is updated by running `php database/migrate.php` after restoring.

## Scanning with a phone camera (HTTPS)

Browsers only allow the camera on **HTTPS** pages, or on the server computer itself at `http://localhost`. XAMPP already has HTTPS turned on with its own certificate:

1. Find the server computer's address: run `ipconfig` and look for the IPv4 address, e.g. `192.168.1.20`.
2. On the teacher's phone, connected to the **same Wi-Fi**, open `https://192.168.1.20/student-portal/staff/login.php`.
3. The browser warns that the certificate isn't trusted. That's expected for XAMPP's built-in certificate. Choose **Advanced → Proceed** once.
4. On the attendance page, press **Start camera** and allow camera access.

If the phone can't open the page at all, allow Apache through Windows Firewall (XAMPP asks the first time). A **USB QR scanner** plugged into a laptop needs none of this: it types the code into the box on the attendance page.

## Security

- **Learner sign-in:** LRN only by default. LRNs aren't secret (they're on report cards and IDs), so anyone who knows a learner's LRN can see that learner's grades. For more protection choose LRN + birthdate or LRN + password under Setup → Settings.
- **QR cards:** each card holds a random secret code, not the LRN, so nobody can make a card for someone else. Reissuing a card turns off the old one. After each scan the teacher's screen shows the learner's name and photo.
- **Who can see what:** parents only see learners linked to their account (linking needs the LRN **and** the link code; sign-up locks after 5 wrong codes). Teachers only see their own classes and advisory. Learners only see their own records and their section's materials. Messages are only visible to the parent and the staff member in the conversation. Every page and every file download checks this.
- **Uploaded files** (excuse letters, materials, photos) are checked for type and size, stored outside `public/` under random names, and only sent after a permission check.
- **Locked grades:** after a quarter's deadline, grades change only through an approved change request, and every change is recorded in the grade history.
- **Lockouts:** sign-ins lock for 15 minutes after 5 failed tries for the same account, or 20 from one IP address.
- **Deactivating** a staff or parent account signs that person out on their next click.
- **Code safeguards:** prepared statements for every query, a CSRF token on every form and scan, HTML-escaped output, `password_hash` passwords, pages not cached, and no framing (clickjacking protection).
- Only `public/` is reachable from the browser.
- **Inactivity sign-out:** learners after 15 minutes, staff and parents after 60.
- **Privacy notice** (`/privacy.php`, linked in the footer): what the portal stores and who sees it (Data Privacy Act of 2012). Parents accept it when they register. Set the data privacy contact under Settings.

## Before using it for real

- In `config/config.php`, set `debug` to `false`.
- Run `php database/install.php` **without** `--seed` on a new database, and sign in as `admin` with the printed password. Fill in **Setup → Settings**.
- **Parents at home** can only reach the portal if it is online. A XAMPP server on a school PC is only reachable on the school network. Put the portal on web hosting that supports PHP and MySQL (with HTTPS), or ask your IT person to make the school server reachable. Set the *Portal address for parents* setting; it is printed on parent link slips.
- Set up regular backups (Setup → Backup).

## Folders

```
config/config.php     settings (database, defaults)
database/             schema.sql, migrations/ (001–005), install.php, migrate.php, demo data
src/                  shared code: database, sign-in, attendance, notifications, report card, class record,
                      grading periods, at-risk, schedule, news, messages, document requests, DepEd forms, backups
templates/            page header/footer and shared page parts
storage/              uploaded files and server backups (not reachable from the browser)
public/               the pages
  index.php, grades.php       learner sign-in and report card
  learner/                    learner home, schedule, subjects, scores, password
  staff/                      staff sign-in, password change
  teacher/                    classes, grades, class record, attendance, materials, schedule, advisory, excuse letters, at-risk
  parent/                     parent sign-up, sign-in, dashboard, child page, notifications, excuse letters
  admin/                      dashboard, learners, sections, subjects, staff, parents, records, reports, profiles, backup
  print/                      SF9, SF10, certificates
  messages.php, requests.php, news.php, privacy.php, file.php
  manifest.webmanifest, sw.js, offline.html   installable app
```

Settings in `config/config.php`: `grade_levels` (11 and 12) and `strands`; grades `grade_min` 60, `grade_max` 100, `passing_grade` 75; `late_after_minutes` (15); sign-in lockout and inactivity timeouts. Most school details are set in the portal under Setup → Settings.
