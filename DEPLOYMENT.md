# Yaan Backend – Deployment & Production Operations Guide

This document details the configuration, security controls, and deployment process for the **Yaan Backend** API (Laravel 11, PHP 8.2).

---

## 1. Architecture Overview

- **Framework:** Laravel 11.x (PHP 8.2)
- **Database:** PostgreSQL (Hosted on Neon DB) with `sslmode=require`
- **Containerization:** Docker (`Dockerfile`, `docker-entrypoint.sh`)
- **Hosting Platform:** Render (Free or Paid Web Service, Singapore/Frankfurt/Oregon)
- **Clients:**
  - Customer Android Mobile App (Role: `user`)
  - Hotel-Owner Android Mobile App (Role: `owner`)
  - Admin Web Control Panel in `/public/admin/` (Role: `admin`)
- **Third-Party Integrations:**
  - Firebase Authentication (`POST /api/verify-otp`)
  - Razorpay Payment Gateway & Webhook (`/api/razorpay-webhook`)
  - Google Maps Geocoding & Places Proxy (`/api/maps/proxy`)

---

## 2. Render Deployment Setup (`render.yaml`)

The repository includes a declarative `render.yaml` specification for Render:

```yaml
services:
  - type: web
    name: yaan-backend
    env: docker
    dockerfilePath: ./Dockerfile
    plan: free
    region: singapore
```

### Automatic Build and Startup Pipeline
When code is pushed to `main`:
1. Render builds the Docker image via `Dockerfile`.
2. `docker-entrypoint.sh` executes on container boot:
   - Configures Apache to listen on Render's dynamic `$PORT`.
   - Creates required storage directories and sets permissions (`www-data:www-data`, `775`).
   - Runs `php artisan storage:link --force`.
   - Caches config, routes, and views (`config:cache`, `route:cache`, `view:cache`).
   - If `RUN_MIGRATIONS=true`, executes `php artisan migrate --force` and runs idempotent seeders (`AdminUserSeeder`, `AmenitySeeder`).
   - Starts Laravel's background worker (`php artisan schedule:work`).
   - Launches Apache in the foreground.

---

## 3. Mandatory Environment Variables

Set the following environment variables in the **Render Dashboard -> yaan-backend -> Environment**:

| Variable | Description | Example / Recommended Value |
|---|---|---|
| `APP_ENV` | Application environment | `production` |
| `APP_DEBUG` | Detailed debug error pages | `false` |
| `APP_KEY` | Laravel encryption key | `base64:...` (Generate with `php artisan key:generate --show`) |
| `APP_URL` | Canonical backend URL | `https://yaan-backend.onrender.com` |
| `APP_FRONTEND_URL` | Frontend URL | `https://yaan-backend.onrender.com` |
| `DB_CONNECTION` | Database driver | `pgsql` |
| `DB_HOST` | Neon DB endpoint hostname | `ep-xyz.ap-southeast-1.aws.neon.tech` |
| `DB_PORT` | PostgreSQL port | `5432` |
| `DB_DATABASE` | PostgreSQL database name | `neondb` |
| `DB_USERNAME` | PostgreSQL database username | `neondb_owner` |
| `DB_PASSWORD` | PostgreSQL database password | `<secure_db_password>` |
| `DB_SSLMODE` | SSL Mode for PostgreSQL connection | `require` |
| `RUN_MIGRATIONS` | Auto-run migrations on deploy | `true` |
| `ADMIN_EMAIL` | Bootstrap Administrator Email | `admin@yaan.com` |
| `ADMIN_PASSWORD` | Bootstrap Administrator Password | `<strong_random_password_32_chars>` |
| `RAZORPAY_KEY_ID` | Razorpay API Key ID | `rzp_live_...` |
| `RAZORPAY_KEY_SECRET` | Razorpay API Secret (HMAC verification) | `<live_secret>` |
| `RAZORPAY_WEBHOOK_SECRET` | Razorpay Webhook Secret | `<webhook_secret>` |
| `GOOGLE_MAPS_API_KEY` | Google Maps Platform API key | `AIzaSy...` |
| `FIREBASE_PROJECT_ID` | Firebase Project ID | `yaan-12663` |
| `FIREBASE_CREDENTIALS` | Path to service account JSON file | `/etc/secrets/firebase-credentials.json` |

> [!IMPORTANT]
> Never commit secrets or actual credentials into Git or `.env.example`. All variables marked `sync: false` in `render.yaml` must be populated through Render's secure Secret Manager.

### Firebase Service Account JSON on Render
To mount the Firebase service account JSON:
1. In Render Dashboard, go to **Environment** > **Secret Files**.
2. Create a secret file named `/etc/secrets/firebase-credentials.json`.
3. Paste the full JSON content downloaded from Firebase Console (Project Settings -> Service Accounts -> Generate New Private Key).

---

## 4. Secret Rotation Guidelines

### A. Razorpay Secrets (`RAZORPAY_KEY_SECRET`, `RAZORPAY_WEBHOOK_SECRET`)
1. Generate the new key and webhook secret in Razorpay Dashboard.
2. In Render, update `RAZORPAY_KEY_SECRET` and `RAZORPAY_WEBHOOK_SECRET`.
3. Trigger a redeploy.
4. Verify by running a test transaction or inspecting webhook response logs.

### B. Admin Password (`ADMIN_PASSWORD`)
1. Update `ADMIN_PASSWORD` in the Render Environment Variables tab.
2. The `AdminUserSeeder` runs automatically on next deploy or manual restart, securely hashing and updating the admin user's credentials.

### C. Google Maps API Key (`GOOGLE_MAPS_API_KEY`)
1. In Google Cloud Console, restrict the key to `Geocoding API` and `Places API`.
2. Restrict IP addresses to Render outbound IPs if available, or keep key securely on the backend proxy (`/api/maps/proxy`). Mobile clients must **never** receive the raw Google Maps API key.

---

## 5. Scheduled Tasks & Anti-Sleep Setup

### Laravel Scheduler
`docker-entrypoint.sh` starts `php artisan schedule:work &` in the container background to process scheduled tasks defined in `routes/console.php`:
- Daily: `users:purge-unverified` (Purges abandoned unverified registrations older than 24 hours).

Unpaid slot holds need no background job: `Booking::holdingSlot()` ignores pending bookings older than 15 minutes, so they expire on their own.

### Anti-Sleep Ping (Render Free Plan)
Free-tier instances spin down after 15 minutes without inbound traffic. Waking up takes 30-60 seconds,
which is longer than most app requests wait (35 seconds), so the first user after a quiet period gets an error.

**Ping URL:** `https://yaan-backend.onrender.com/api/ping` (also `/ping`, `/health`, `/api/health`).
These URLs use no session, cookie or database, so pinging them never wakes the Neon database.
Never ping a URL that reads the database (for example `/api/hotels`): Neon's free plan has about
400 hours of compute per month, so a database kept awake 24/7 would be suspended mid-month.

**1. Built-in self-ping (on by default).** `docker-entrypoint.sh` calls `$RENDER_EXTERNAL_URL/api/ping`
every 5 minutes. It runs only on Render (Render sets `RENDER_EXTERNAL_URL`). Failures appear in the Render
logs as `Keep-alive ping failed`. Turn it off with `KEEP_ALIVE=false`, or point it elsewhere with `KEEP_ALIVE_URL`.

**2. External monitor (recommended as backup and alert).** Create a free UptimeRobot (or cron-job.org)
HTTP monitor for `https://yaan-backend.onrender.com/api/ping`, every 5 minutes, with email alerts.
It wakes the server if it ever falls asleep and tells you when production is down.

**3. Render health check.** `render.yaml` sets `healthCheckPath: /api/ping`. If the service was created
without the Blueprint, set it by hand: Render Dashboard -> yaan-backend -> Settings -> Health Check Path.

> [!WARNING]
> Render gives 750 free instance hours per **workspace** per month, and one service awake all month uses ~744.
> If another free web service in the same workspace is also kept awake, the hours run out and Render
> suspends **all** free services until the next month. Keep only this service awake, or move it to a paid
> instance type (paid instances never sleep).

---

## 6. Security & Hardening Reference

- **Dual-Response Error Contract:** Every API error response returns both `error` and `message` keys (e.g. `{"error": "...", "message": "..."}`) ensuring 100% compatibility with older and newer mobile app builds.
- **Strict IST Date Authority:** Room availability and check-in dates are calculated using `App\Support\BusinessDate` (Asia/Kolkata), while database records remain standardized in UTC.
- **Private KYC Storage:** Owner Aadhaar/PAN cards and hotel documents are uploaded to `storage/app/kyc_docs/` (non-public). Directory traversal and unauthenticated access are blocked with HTTP 403.
- **Safe Upload Verification:** All file uploads are verified by `App\Support\SafeUpload`, enforcing MIME type checks, image re-encoding, and disallowing executable file extensions (`.php`, `.phtml`, `.sh`, `.exe`).
- **Admin Panel XSS Hardening:** `public/admin/app.js` and `invoice.html` sanitize all dynamic content using DOM element assignment (`textContent`) and `esc()` HTML escaping.
- **Brute Force Protection:** Strict rate limiters (`throttle:login`, 5 attempts per minute) are enforced on all authentication routes.

---

## 7. Testing & Verification

Run the test suite locally or in CI using PHP 8.2:

```bash
# Run all unit, integration, and security test suites
php vendor/bin/phpunit --testdox
```

### Test Coverage Highlights
- `RoleCrossAppRestrictionTest.php` (5 tests): Blocks customer/owner cross-app login and registration.
- `SecurityFixesTest.php` (14 tests): Verifies brute-force throttling, private KYC storage, media directory traversal protection, safe file upload validation, and Sanctum expiration.
- `BookingAndAccountFixesTest.php` (24 tests): Tests slot holds, Razorpay signature validation, payment conflict resolution, 5-booking cash limit, Indian vehicle number regex validation, server-side pricing, and abandoned account purging.
- `SystemIntegrationTest.php` (8 tests): End-to-end user registration, hotel search, booking lifecycle, and multi-tenant authorization boundaries.
