# 💌 CONNECT SRMAP — Confessions Page

A standalone anonymous **Confessions** page for CONNECT SRMAP, built with PHP, MySQL, JavaScript and Tailwind CSS.

The page supports anonymous submissions, scheduled reveals, weekly limits, likes, share links and filtering. The database is designed so an administrator can later create a separate admin page for schedule and moderation management.

## ✨ Features

- Student-login protection
- Anonymous confession submission
- Automatic submission schedule
- Configurable opening/closing times
- Scheduled confession reveals
- Weekly submission limit
- Likes
- Shareable confession links
- Month and ID filters
- Current-week self deletion
- Responsive dark/neon UI
- Database-driven schedule configuration
- IST / `Asia/Kolkata` schedule handling

## 📁 Included

```text
confessions.php
schema.sql
README.md
```

This repository contains the **Confessions page only**. It does not include a complete CONNECT SRMAP login system or a ready-made admin dashboard.

## 🛠️ Requirements

- PHP 7.4+ / PHP 8+
- MySQL 5.7+ or MySQL 8+
- PDO MySQL
- Existing student login/session system
- Existing `students` table

The page expects:

```php
$_SESSION['register_number']
$_SESSION['name']
```

If the name is missing from the session, it looks up the name using:

```sql
SELECT name FROM students WHERE register_number = ?
```

Therefore your existing `students` table must contain at least:

```text
register_number
name
```

## 🗄️ Database Setup

### 1. Select your existing database

Use the same database that contains your `students` table.

### 2. Import `schema.sql`

In phpMyAdmin:

1. Select your database.
2. Click **Import**.
3. Select `schema.sql`.
4. Click **Go**.

The SQL creates:

```text
confessions
confession_likes
confession_settings
confession_schedule_rules
```

Do not create another `students` table if your main project already has one.

## 📊 Tables

### `confessions`

Stores confession content and internal sender information.

Important fields:

```text
id
confession_text
instagram_account
register_number
new_optional_text
sender_register_number
sender_name
display_date
reveal_at
share_token
like_count
fake_like_count
created_at
```

`sender_register_number` and `sender_name` are internal/private fields and should never be shown in the public feed.

### `confession_likes`

Stores likes using:

```text
confession_id
user_ip
liker_register_number
liker_name
created_at
```

The page only accepts likes after the confession has been revealed.

### `confession_settings`

Stores global settings:

```text
max_submissions
submission_days
```

Day numbers:

```text
1 = Monday
2 = Tuesday
3 = Wednesday
4 = Thursday
5 = Friday
6 = Saturday
7 = Sunday
```

Default:

```text
max_submissions = 2
submission_days = 5,6
```

### `confession_schedule_rules`

Controls:

```text
rule_name
rule_type
submission_days
open_date
close_date
reveal_date
open_time
close_time
reveal_time
weekly_reveal_day
month_open_day
month_close_day
month_reveal_day
max_submissions
priority
is_enabled
```

The PHP page reads enabled rules ordered by priority.

## ⏰ Schedule Examples

### Change weekly limit

```sql
UPDATE confession_settings
SET setting_value = '3'
WHERE setting_name = 'max_submissions';
```

### Friday + Saturday + Sunday

```sql
UPDATE confession_settings
SET setting_value = '5,6,7'
WHERE setting_name = 'submission_days';
```

### Create a weekly schedule

```sql
INSERT INTO confession_schedule_rules
(rule_name, rule_type, submission_days,
 open_time, close_time, reveal_time,
 weekly_reveal_day, max_submissions, priority, is_enabled)
VALUES
('Weekend Confessions', 'weekly', '5,6',
 '00:00:00', '23:59:59', '00:00:00',
 7, 2, 100, 1);
```

### Disable a schedule

```sql
UPDATE confession_schedule_rules
SET is_enabled = 0
WHERE id = 1;
```

## 👨‍💻 Admin Page

A ready-made admin page is **not included** because this repository contains only the Confessions page.

To create one, add:

```text
confessions_admin.php
```

The admin page should manage:

### Dashboard

Show:

```text
Total Confessions
Total Likes
This Week's Submissions
Locked Confessions
Revealed Confessions
```

### Schedule Manager

Allow administrators to:

- Create schedules
- Edit schedules
- Enable/disable schedules
- Delete schedules
- Set priority
- Set submission days
- Set opening time
- Set closing time
- Set reveal date/time
- Set weekly/monthly rules
- Set weekly submission limit

### Confession Manager

Allow administrators to:

- Search confessions
- Filter by date
- View locked/revealed status
- Delete inappropriate content
- View like counts
- Copy share links

### Security

Protect the admin page with your existing admin authentication.

Example:

```php
session_start();

if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    http_response_code(403);
    exit('Access denied.');
}
```

Adapt this to your own CONNECT SRMAP admin/session system.

Do not create an admin page that relies only on a URL such as:

```text
confessions_admin.php?admin=1
```

## 🔌 Database Connection

Before deployment, change the database configuration in `confessions.php` to your own server.

Prefer environment variables or a private configuration file.

Never publish real credentials.

## 🔐 Privacy & Security

The page stores internal sender information so users can manage their own submissions.

Sensitive fields include:

```text
sender_register_number
sender_name
user_ip
liker_register_number
liker_name
```

Do not expose these publicly.

Do not upload production database dumps containing real student data.

## 🚨 VERY IMPORTANT — GitHub

The original page contains database credentials.

Before pushing to a **public** GitHub repository, remove the real:

```text
database username
database password
database name
```

Replace them with placeholders or load them from a private configuration file.

Also do not commit:

```text
.env
config.php
database.php
db.php
production database dumps
```

Recommended `.gitignore`:

```gitignore
.env
.env.*
!.env.example

node_modules/
vendor/

*.log

.DS_Store
Thumbs.db

/config.php
/database.php
/db.php

*.sql.gz
backup/
backups/
```

## 🚀 GitHub Push Commands

From your project folder:

```bash
git init
```

```bash
git branch -M main
```

Add your repository:

```bash
git remote add origin https://github.com/YOUR_USERNAME/YOUR_REPOSITORY.git
```

Check:

```bash
git remote -v
```

Check sensitive files before staging:

```bash
git status
```

Then:

```bash
git add .
```

Commit:

```bash
git commit -m "Add CONNECT SRMAP Confessions page"
```

Push:

```bash
git push -u origin main
```

If the GitHub repository already has an initial README/commit:

```bash
git pull --rebase origin main
```

Then:

```bash
git push -u origin main
```

## 🧪 Testing Checklist

### Database

- [ ] Four confession tables created
- [ ] Existing `students` table works
- [ ] `register_number` exists
- [ ] `name` exists

### Login

- [ ] Logged-out users are redirected to login
- [ ] Logged-in users can access Confessions
- [ ] Register number exists in session

### Submission

- [ ] Submission schedule works
- [ ] Weekly limit works
- [ ] Confession is inserted
- [ ] Reveal time is stored
- [ ] Share token is generated

### Feed

- [ ] Locked confessions remain hidden
- [ ] Revealed confessions appear
- [ ] Month filtering works
- [ ] ID filtering works
- [ ] Share links work

### Likes

- [ ] Likes work after reveal
- [ ] Duplicate IP likes are prevented
- [ ] Like count updates

### Admin

- [ ] Admin page requires authentication
- [ ] Schedule can be changed
- [ ] Schedule can be enabled/disabled
- [ ] Submission limit can be changed
- [ ] Confessions can be moderated

## 📌 Source Notes

The current Confessions page uses the logged-in student's session register number, reads the student's name from the existing `students` table when necessary, reads schedule/settings from the confession configuration tables, inserts submissions into `confessions`, records likes in `confession_likes`, and only shows revealed confessions in the public feed.

## 📄 License

MIT License

## 👨‍💻 Author

**NaniBalaga**

CONNECT SRMAP — Confessions
