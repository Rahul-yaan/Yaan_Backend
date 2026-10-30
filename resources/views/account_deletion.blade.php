<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Your Account - Yaan App</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #ea580c;
            --dark: #0f172a;
            --gray-800: #1e293b;
            --gray-600: #475569;
            --border: #e2e8f0;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f1f5f9; color: var(--gray-800); line-height: 1.65; padding-bottom: 60px; }
        .header-bg { background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: #ffffff; padding: 48px 24px 72px 24px; text-align: center; }
        .brand-badge { display: inline-flex; align-items: center; gap: 8px; background: rgba(234, 88, 12, 0.15); border: 1px solid rgba(234, 88, 12, 0.4); color: #fb923c; font-size: 13px; font-weight: 700; padding: 6px 14px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 16px; }
        .header-bg h1 { font-size: 32px; font-weight: 800; margin-bottom: 8px; }
        .header-bg p { color: #94a3b8; font-size: 15px; }
        .container { max-width: 860px; margin: -36px auto 0 auto; padding: 0 20px; }
        .card { background: #ffffff; border-radius: 16px; padding: 32px; box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.05); border: 1px solid var(--border); margin-bottom: 24px; }
        .section-item { margin-bottom: 28px; padding-bottom: 24px; border-bottom: 1px solid #f1f5f9; }
        .section-item:last-child { margin-bottom: 0; padding-bottom: 0; border-bottom: none; }
        .section-title { font-size: 20px; font-weight: 700; color: var(--dark); margin-bottom: 12px; }
        .section-text { color: var(--gray-600); font-size: 15px; line-height: 1.7; }
        .bullet-list { margin: 12px 0 16px 20px; color: var(--gray-600); font-size: 15px; }
        .bullet-list li { margin-bottom: 8px; }
        a { color: var(--primary); font-weight: 600; }
        .footer-note { text-align: center; margin-top: 32px; font-size: 13px; color: var(--gray-600); }
    </style>
</head>
<body>

    <div class="header-bg">
        <div class="brand-badge">🗑️ Account Deletion</div>
        <h1>Delete Your Yaan Account</h1>
        <p>For the Yaan customer app and the Yaan Partner (hotel owner) app</p>
    </div>

    <div class="container">
        <div class="card">
            <div class="section-item">
                <h2 class="section-title">Delete from the app</h2>
                <ul class="bullet-list">
                    <li><strong>Yaan (customer app):</strong> open the app, go to <strong>Profile</strong>, tap <strong>Delete Account</strong> and confirm.</li>
                    <li><strong>Yaan Partner (hotel owner app):</strong> open the app, go to <strong>Account</strong>, tap <strong>Delete Account</strong> and confirm.</li>
                </ul>
                <p class="section-text">Your account is deleted right away and you are logged out.</p>
            </div>

            <div class="section-item">
                <h2 class="section-title">Can't open the app?</h2>
                <p class="section-text">
                    Email <a href="mailto:{{ $supportEmail }}?subject=Delete%20my%20Yaan%20account">{{ $supportEmail }}</a>
                    or call {{ $supportPhone }} from your registered mobile number, and ask us to delete your account.
                    We will confirm it is you and delete the account within 7 days.
                </p>
            </div>

            <div class="section-item">
                <h2 class="section-title">What we delete</h2>
                <ul class="bullet-list">
                    <li>Your name, email address, mobile number, password and profile photo</li>
                    <li>For hotel owners: Aadhaar, PAN, bank details, GST/FSSAI details and all uploaded KYC documents. Your hotel is removed from the app.</li>
                </ul>
            </div>

            <div class="section-item">
                <h2 class="section-title">What we keep</h2>
                <p class="section-text">
                    Booking and payment records (date, amount, hotel, transaction ID) are kept without your name or contact details,
                    because Indian tax law (GST) requires us to keep invoice records for up to 8 years. Reviews you wrote stay visible as "Deleted User".
                </p>
            </div>
        </div>

        <p class="footer-note">© {{ date('Y') }} Yaan. All rights reserved.</p>
    </div>

</body>
</html>
