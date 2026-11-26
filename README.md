# AralTrack: school portal for Senior High School

A school portal for Grade 11 and 12 learners, their parents, subject teachers and school staff. One installation can
serve **many schools** (each with its own separate data), run by a **platform owner**; see *Many schools* below.

- **Subject teachers** keep a DepEd **class record** (Written Works, Performance Tasks, Quarterly Assessment), upload or type quarterly grades, take **class attendance by scanning learners' QR ID cards**, and post class materials.
- **Parents** have their own accounts. They see each child's attendance in every class, the report card, school news and the calendar. They can send **excuse letters**, **message teachers** and **request documents**. **Notifications** appear inside the portal only (no email, no SMS).
- **Learners** sign in with their **LRN** (by default). They see their home page, report card, class schedule, subjects with materials and their scores, and can request documents.
- **Advisers** see their section's attendance, review excuse letters, rate observed values and print SF9 report cards.
- The **registrar (admin)** sets up the school year, sections, subjects, staff, parents and learners; prints QR IDs, DepEd forms and certificates; and handles grade deadlines, change requests, the honor roll, the year-end rollover and backups.
- The **principal** sees school reports and all records, and approves grade changes. A **guidance counselor** sees learner profiles, at-risk learners and excuse letters.
- The **Super Admin** controls the whole portal: turns parts of it on or off (attendance, grading, messages, …), decides who can sign in (teachers & staff, learners, parents), puts the portal in maintenance mode, controls each person's access, and sees every sign-in and every change.

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
| Super Admin | `superadmin` / `super123` at `/staff/login.php` |
| Registrar (admin) | `admin` / `admin123` |
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

### Super Admin

The Super Admin has everything the registrar has, plus the **Control** menu:

- **Control panel**
  - *Parts of the system*, each on or off: Attendance (QR scanning), Grading (with *Grade encoding*, *Learners see their grades* and *Parents see grades*), Class record, Excuse letters, Messages, Class materials, Document requests, News & calendar. A part that is off disappears from the menus of teachers, learners and parents, and its pages say it is turned off. Nothing is deleted: turn it back on and everything is there.
  - *Sign-in*: teachers & staff, learners and parents each on or off; new parent accounts open or closed; how learners sign in (LRN only, LRN + birthdate, LRN + password). Turning a group off also signs its people out on their next click. A Super Admin can always sign in.
  - *Maintenance mode*: only Super Admins can sign in; everyone else sees your message.
  - *Sign everyone out*: ends every session on every device except yours.
- **Accounts & sign-in**: every teacher/staff, parent and learner account with its last sign-in (time and device). For each person: turn sign-in off (with a reason they see) or back on, sign out of every device, unlock after too many wrong tries, reset the password (shown once). One click turns off sign-in for learners who graduated, transferred out or dropped.
- **Manage one person** (the Manage button in Accounts & sign-in): everything above for that person, plus **what they can use**, one switch per part:
  - a teacher: take attendance, type/upload/publish grades, class record, post materials, review excuse letters, messages, news;
  - a learner: report card (e.g. on hold until requirements are settled), attendance, My scores, class materials, document requests, news;
  - a parent: report card, attendance (and absence/late notifications), excuse letters, messages, document requests, news.

  An optional reason is shown to them. A part works for a person only when it is ON in the control panel **and** not turned off for that person; it applies on their next click and deletes nothing.
- **Activity log**: every sign-in attempt (who, when, device, IP address, and why it failed), and every account, access and settings change (who changed what). Filter, and download as Excel. Entries are kept for 365 days (changeable).

Only a Super Admin can create, change or turn off Admin and Super Admin accounts. You can't change your own role or turn off your own account, so the portal always keeps a Super Admin. On a new install (`php database/install.php`), the `admin` account is the Super Admin. When an existing portal is updated, its first active admin account becomes the Super Admin.

### Registrar (admin)

Do these in order; the dashboard has a checklist.
1. **Setup → Settings**: school name, School ID, division, region, address, principal's name (printed on forms and certificates), the current semester, when a scan counts as Late, and how many absences make a learner "at risk".
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
10. Backups are made by the platform owner (they hold every school's data); see *Backups* below.

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

Sign in at **Learner sign-in** (`learner/login.php`, linked from the home page). The learner home shows today's classes and attendance, this month's totals, news and the calendar. **Report card** (one table per semester, like the SF9), **Schedule**, **Subjects** (materials from teachers and **My scores** per subject), and **Requests**.

**How learners sign in** (Super Admin → Control panel): LRN only (default), LRN + birthdate, or LRN + password (each learner activates their account once with their birthdate; the registrar can reset it). The sign-in page first asks for the LRN; the learner's school decides what else is asked.

## Offline attendance (no internet)

Teachers can take QR attendance without internet. Everything is sent to the server when the connection is back.

- **While online**, opening **My classes** saves the teacher's class lists for this semester on the phone (the "Offline attendance" box shows what is saved; *Update lists* refreshes it). A card is saved only as a SHA-256 fingerprint, never the card code itself. The lists are deleted from the phone when the teacher signs out.
- **Internet drops during class:** the attendance page keeps working. Scans and hand marks are saved on the phone with their exact time (so Late is still right), the page says "saved on this phone", and they are sent automatically when the connection returns. Finish also works.
- **No internet at all:** open **offline-attendance.html** (the *Open offline attendance* button, or the link on the "You're offline" page). Choose the class, confirm the start time, then scan with the camera or a USB scanner, mark by hand, and finish. It works after the phone has visited the portal once while online.
- **Sending:** automatic when online (or press *Send now*). Each offline class has its own id, so sending twice never makes duplicates; parents get their late/absent notifications when it arrives. If the teacher was signed out meanwhile, the records stay on the phone until they sign in again.
- Not offline: signing in for the first time on a phone, entering grades, messages and requests (they can be read
  offline with *Offline copy*, below).

## Offline copy (reading pages without internet)

Learners, parents and teachers can read their pages without internet, as they were when last online.

- **On by default in the installed app** (added to the home screen, usually the person's own phone). In a normal
  browser it is off until the person turns on **Offline copy** in their menu (top right).
- After sign-in the device saves the main pages by itself: learners their home, report card, schedule and subjects;
  parents each child's attendance and grades; teachers *My classes*, schedule, advisory and each class's grades
  and attendance. Every other page of their portal is saved when they open it.
- **No internet:** the saved copy opens with a bar "You're offline · this is your saved copy from …". Buttons that
  save are switched off; the page reloads by itself when the connection is back. A page that isn't saved shows the
  list of pages that are.
- **Privacy:** only pages the server marks as the person's own are kept (`src/OfflinePages.php`): never admin,
  Super Admin or platform pages, sign-in forms or the live attendance page. **Signing out deletes every saved page**,
  and so does turning Offline copy off or another person signing in on the device.

## Installable app and dark mode

- The portal can be **installed like an app** on phones and computers (Chrome or Edge: *Install app* in the footer, or the browser menu → *Install*). It opens full screen with the school crest. Only styles, scripts and icons are kept on the device; pages with grades or names always come from the server, and an offline page is shown without a connection. Installing needs `https://` (or `http://localhost` on the server itself).
- **Dark mode**: the moon/sun button in the top bar. The choice is remembered on that device; by default it follows the device setting. Printing always uses light colours.

## Many schools (platform console)

The system can run many schools, and teachers on their own, from one installation and one database. Each is a
**workspace**: every record is tagged with its school, every page and query only uses the signed-in person's school,
and settings, sign-in switches, maintenance mode and the activity log are per school. The school that was there
before became workspace 1.

- **Home page** (`index.php`): what the system does, for schools and teachers looking at it, a **Request a demo**
  form, and sign-in links for learners, parents and staff. Someone already signed in goes straight to their portal.
- **Platform console** (`platform/login.php`), for the platform owner only, separate from every school account:
  - **Schools**: every school and solo teacher with staff, learners, parents and the last sign-in. **Add a school**
    creates the workspace and its first Super Admin with a temporary password.
  - One school: details and plan, **Pause** (nobody in that school can sign in; nothing is deleted) and **Resume**,
    **Reset password** of its admins, and **Open portal** to help them (the school's activity log records the visit,
    and a banner shows it).
  - **Demo requests** sent from the home page, and **Backups**.
- **First owner account**: sign in as the Super Admin of the first school, then open `platform/login.php` and
  create it (only possible once). With demo data: `owner` / `owner12345`.
- Staff usernames and parent sign-in names are unique across all schools; LRNs, subject codes and school years only
  inside one school. If the same LRN is in two schools, learner sign-in uses the school where the learner is
  enrolled this school year.

## Billing (plans and payments)

- **Plans** (per school or teacher, set in the platform console): *Free* (a school you give it to for free, or the
  limited free plan for a teacher on their own), *Free trial* (everything, for the trial days), *Paid* (until a date).
- **A school pays per staff account**, monthly or yearly; **a teacher on their own** pays one price (*Teacher Pro*).
  Prices, trial days, the days to renew after it ends, and the free teacher plan's number of classes are set under
  **Platform console → Billing**, with the bank transfer instructions schools see.
- **Billing page** (`billing.php`, under Setup/Control for the Super Admin and admins): the plan, paying online with
  GCash, Maya, card or QR Ph (PayMongo's hosted checkout), bank transfer instructions, and past payments.
- **When a school's trial or paid time is over** (after the renewal days), its portal becomes **read-only**: everyone
  can still sign in and see everything, but staff can't change anything (no grades, no attendance) until it renews.
  A banner says so. A teacher on their own falls back to the free plan instead (no new classes over the limit).
- **Bank transfer or cash:** record it under Platform console → Billing; the school is paid for the months entered.
  The console's school page also lets you change a school's plan, trial end and paid-until dates.
- **PayMongo setup** (in `config/config.local.php`, see the example file): `paymongo.secret_key` (start with the
  `sk_test_` key: nothing is charged) and `app_url`. In PayMongo → Developers → Webhooks, add
  `https://yourdomain.com/paymongo-webhook.php` with the event `checkout_session.payment.paid` and put its secret in
  `paymongo.webhook_secret`. Payments are also confirmed when the payer returns from the checkout page, so they
  work even before the webhook is set. On a test computer with debug on and no key, the Billing page offers a
  *test payment* button instead.

## One person, several schools

A learner or parent can be at more than one school or teacher on the system (a transferee, or a teacher using it on
their own). Each school keeps its own record; the records are joined only with proof, so no school can open another
school's records (`src/Accounts.php`).

- **Parents:** one mobile number or email and one password open every school where they have a child. *Add a child*
  accepts a slip from another school or teacher too (the parent's record there is made with the same sign-in).
  Registering at a second school asks for the same password as the first. The menu under their name lists their
  schools; the home page shows their children at other schools. Changing the password changes it everywhere.
  A registrar who resets a parent's password at one school only resets that school's record: it opens with the new
  password alone until the parent sets the same password again.
- **Learners:** the LRN opens every school where the learner passes that school's own sign-in rule (LRN only,
  birthdate or password). If one school asks for the birthdate, the form asks for it.
- **One ID card:** when a learner's card from another school is scanned in a class where a learner with the same
  LRN is enrolled, it is linked: from then on it works in that teacher's classes too (no need to print another card).
  The card in class plus the same LRN in the class list is the proof.

## Teachers on their own (sign-up)

A teacher whose school isn't on the system can create a free account at **signup.php** (linked from the home page
and the staff sign-in page). They get a workspace of their own, with themselves as its only staff account.

- **Sign-up:** name, email, the school where they teach (printed on cards and report cards) and a password. A
  6-digit code is emailed to confirm the address; until then the account can't be used. They sign in with the email.
- **Setup guide** (`teacher/setup.php`, shown until finished): 1 school and school year, 2 the classes they teach
  (a subject in a section; common Senior High School subjects are listed, others can be typed), 3 their learners
  (pasted from Excel or Google Sheets, or imported from an Excel file), 4 QR ID cards and parent slips.
- **Their menu** has only what one teacher needs: My classes, learners and sections, parents, messages, news, and
  Setup. Staff accounts are not available (one teacher per account).
- **Forgot your password?** on the staff sign-in page emails a link (works once, for an hour). School staff without
  an email on their account still ask their admin.
- Learners and parents of a teacher sign in and register exactly like at a school.

**Email is needed for sign-up.** In `config/config.local.php` on the server, set `app_url` (the site's address) and
`mail` (a mailbox to send from; on Hostinger create e.g. `no-reply@yourdomain.com` under hPanel → Emails, then use
`smtp.hostinger.com`, port 465, `ssl`, and that address and password). See `config/config.local.example.php`. Until
both are set, the sign-up page says "Teacher sign-up opens soon". On your own computer with `debug` on, emails are
not sent but saved in `storage/mail/`, and the code is shown on screen. `'teacher_signup' => false` closes sign-up.

## Backups

**Platform console → Backups** (platform owner only, because a backup holds every school's data):
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

- **Learner sign-in:** LRN only by default. LRNs aren't secret (they're on report cards and IDs), so anyone who knows a learner's LRN can see that learner's grades. For more protection, the Super Admin can choose LRN + birthdate or LRN + password in the control panel.
- **QR cards:** each card holds a random secret code, not the LRN, so nobody can make a card for someone else. Reissuing a card turns off the old one. After each scan the teacher's screen shows the learner's name and photo.
- **Who can see what:** parents only see learners linked to their account (linking needs the LRN **and** the link code; sign-up locks after 5 wrong codes). Teachers only see their own classes and advisory. Learners only see their own records and their section's materials. Messages are only visible to the parent and the staff member in the conversation. Every page and every file download checks this.
- **Uploaded files** (excuse letters, materials, photos) are checked for type and size, stored outside `public/` under random names, and only sent after a permission check.
- **Locked grades:** after a quarter's deadline, grades change only through an approved change request, and every change is recorded in the grade history.
- **Lockouts:** sign-ins lock for 15 minutes after 5 failed tries for the same account, or 20 from one IP address.
- **Deactivating** an account, turning a sign-in group off, maintenance mode, "sign out" and "sign everyone out" (Super Admin) all take effect on that person's next click.
- **Sign-in history:** every attempt is recorded with the time, device and IP address (Super Admin → Activity log).
- **Code safeguards:** prepared statements for every query, a CSRF token on every form and scan, HTML-escaped output, `password_hash` passwords, pages not cached, and no framing (clickjacking protection).
- Only `public/` is reachable from the browser.
- **Inactivity sign-out:** learners after 15 minutes, staff and parents after 60.
- **Privacy notice** (`/privacy.php`, linked in the footer): what the portal stores and who sees it (Data Privacy Act of 2012). Parents accept it when they register. Set the data privacy contact under Settings.

## Putting it online (Hostinger or other PHP hosting)

The server's own settings (database password, web address, debug off) go in `config/config.local.php`, which is **not saved in Git**. Copy `config/config.local.example.php` to that name and fill it in. Anything in it replaces the same setting in `config.php`.

On Hostinger (hPanel):
1. **Websites → Manage → Advanced → PHP Configuration**: choose PHP **8.2** or newer.
2. **Databases → MySQL Databases**: create a database and user. Note the full names (with the `u123456789_` prefix) and the password. The host is `localhost`.
3. **Security → SSL**: install the free SSL certificate and turn on **Force HTTPS** (needed for phone camera scanning and the installable app).
4. Put the files in **`public_html`** (the whole project: `.htaccess`, `config/`, `public/`, `src/`, …), either:
   - **Advanced → Git**: deploy from your GitHub repository into `public_html` (the folder must be empty), or
   - **Files → File Manager**: upload a zip of the project and extract it in `public_html`.
5. Add the Composer libraries: with **Advanced → SSH Access**, run `cd public_html && composer install --no-dev`. Without SSH, upload your local `vendor/` folder (zip it, upload, extract).
6. In File Manager, create `config/config.local.php` from the example: `base_url` `''` (or `'/folder'` if the portal is in a folder), `debug` `false`, and the database name, user and password.
7. Create the tables: over SSH, `cd public_html && php database/install.php` (prints the admin password). Without SSH, import a `.sql` file in **Databases → phpMyAdmin** (make it on your computer with `php database/install.php --db=araltrack_live` and export that database, or use a Setup → Backup file).
8. Sign in as `admin`, change the password, and fill in **Setup → Settings**, including *Portal address for parents* (`https://yourdomain.com`).

**Automatic updates from GitHub** (every `git push` updates the website):
1. In hPanel → **Advanced → Git**, add the repository (`https://github.com/<you>/<repo>.git`, branch `main`, folder `public_html` left empty in the "Directory" box) and press **Deploy** once. For a private repository, first add the SSH key hPanel shows there to GitHub → repository **Settings → Deploy keys**.
2. In the same Git page, open **Auto Deployment** and copy the **webhook URL**.
3. On GitHub: repository **Settings → Webhooks → Add webhook**. Paste the URL in *Payload URL*, keep *Just the push event*, and save. GitHub shows a green tick after the first delivery.

After each push, Hostinger pulls the new code. Files that aren't in Git stay as they are on the server: `config/config.local.php`, `vendor/` and uploaded files in `storage/`. Database changes in the new code are applied on the first page load afterwards (`auto_migrate` in `config/config.php`; they only add tables and columns and keep all data). If an update changes `composer.json`, run `composer install --no-dev` again (or upload the new `vendor/`).

Without automatic updates: deploy the new code (Git → Deploy, or upload the changed files); the database is updated on the next page load, or run `php database/migrate.php` over SSH.

The root `.htaccess` sends every request to `public/`, so `config/`, `src/`, `database/` and `storage/` can't be opened from the browser. The portal sets each database connection to the portal's time zone (`Asia/Manila`), so times are right even when the hosting's MySQL runs on UTC.

**Don't put the demo data online with the demo passwords**: they are published in this README. Install without `--seed`, or change every demo account's password first.

## Before using it for real

- In `config/config.php`, set `debug` to `false`.
- Run `php database/install.php` **without** `--seed` on a new database, and sign in as `admin` (the Super Admin) with the printed password. Fill in **Setup → Settings**, check the **Control panel**, and create a separate Admin account for the registrar. Then create the platform owner account at `platform/login.php`.
- **Parents at home** can only reach the portal if it is online. A XAMPP server on a school PC is only reachable on the school network. Put the portal on web hosting that supports PHP and MySQL (with HTTPS), or ask your IT person to make the school server reachable. Set the *Portal address for parents* setting; it is printed on parent link slips.
- Set up regular backups (Platform console → Backups).

## Folders

```
config/config.php     settings (database, defaults)
database/             schema.sql, migrations/ (001–012), install.php, migrate.php, demo data
src/                  shared code: database, sign-in, attendance, notifications, report card, class record,
                      grading periods, at-risk, schedule, news, messages, document requests, DepEd forms, backups,
                      Super Admin switches and logs (Access.php), the current school (Workspace.php)
templates/            page header/footer and shared page parts
storage/              uploaded files and server backups (not reachable from the browser)
public/               the pages
  index.php                   public home page (the product, demo request form, sign-in links)
  grades.php                  learner report card
  learner/                    learner sign-in, home, schedule, subjects, scores, password
  signup.php                  teacher sign-up (a workspace of their own)
  billing.php, paymongo-webhook.php   plan and payments (PayMongo)
  staff/                      staff sign-in, email code, forgot/reset password, password change
  teacher/                    setup guide (teachers on their own), classes, grades, class record, attendance, materials, schedule, advisory, excuse letters, at-risk
  parent/                     parent sign-up, sign-in, dashboard, child page, notifications, excuse letters
  admin/                      dashboard, learners, sections, subjects, staff, parents, records, reports, profiles
  superadmin/                 control panel, accounts & sign-in, one person's access, activity log
  platform/                   platform owner console: schools, demo requests, billing, backups
  print/                      SF9, SF10, certificates
  messages.php, requests.php, news.php, privacy.php, file.php
  manifest.webmanifest, sw.js, offline.html   installable app
  offline-attendance.html     attendance without internet (assets/offline-attendance.js, offline-page.js)
```

Settings in `config/config.php`: `grade_levels` (11 and 12) and `strands`; grades `grade_min` 60, `grade_max` 100, `passing_grade` 75; `late_after_minutes` (15); sign-in lockout and inactivity timeouts. Most school details are set in the portal under Setup → Settings.
