<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy - Yaan App</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #ea580c;
            --primary-hover: #c2410c;
            --primary-light: #fff7ed;
            --dark: #0f172a;
            --gray-800: #1e293b;
            --gray-600: #475569;
            --gray-100: #f8fafc;
            --border: #e2e8f0;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: #f1f5f9;
            color: var(--gray-800);
            line-height: 1.65;
            padding-bottom: 60px;
        }

        .header-bg {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #ffffff;
            padding: 48px 24px 72px 24px;
            text-align: center;
            position: relative;
        }

        .brand-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(234, 88, 12, 0.15);
            border: 1px solid rgba(234, 88, 12, 0.4);
            color: #fb923c;
            font-size: 13px;
            font-weight: 700;
            padding: 6px 14px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 16px;
        }

        .header-bg h1 {
            font-size: 32px;
            font-weight: 800;
            letter-spacing: -0.5px;
            margin-bottom: 8px;
        }

        .header-bg p {
            color: #94a3b8;
            font-size: 15px;
        }

        .container {
            max-width: 860px;
            margin: -36px auto 0 auto;
            padding: 0 20px;
        }

        .nav-tabs {
            display: flex;
            background: #ffffff;
            border-radius: 12px;
            padding: 6px;
            box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.05);
            margin-bottom: 24px;
            border: 1px solid var(--border);
        }

        .nav-tab {
            flex: 1;
            text-align: center;
            padding: 12px 16px;
            font-weight: 700;
            font-size: 14px;
            border-radius: 8px;
            text-decoration: none;
            color: var(--gray-600);
            transition: all 0.2s ease;
        }

        .nav-tab.active {
            background: var(--primary);
            color: #ffffff;
            box-shadow: 0 2px 8px rgba(234, 88, 12, 0.3);
        }

        .card {
            background: #ffffff;
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--border);
            margin-bottom: 24px;
        }

        .meta-box {
            background: var(--primary-light);
            border: 1px solid #ffedd5;
            border-radius: 12px;
            padding: 16px 20px;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 28px;
            font-size: 14px;
        }

        .meta-item strong {
            color: var(--primary);
        }

        .section-item {
            margin-bottom: 28px;
            padding-bottom: 24px;
            border-bottom: 1px solid #f1f5f9;
        }

        .section-item:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }

        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-text {
            color: var(--gray-600);
            font-size: 15px;
            line-height: 1.7;
        }

        .bullet-list {
            margin: 12px 0 16px 20px;
            color: var(--gray-600);
            font-size: 15px;
        }

        .bullet-list li {
            margin-bottom: 8px;
        }

        .contact-box {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #ffffff;
            border-radius: 16px;
            padding: 28px;
            margin-top: 32px;
        }

        .contact-box h3 {
            font-size: 18px;
            margin-bottom: 12px;
            color: #fb923c;
        }

        .contact-box a {
            color: #60a5fa;
            text-decoration: none;
        }

        .contact-box a:hover {
            text-decoration: underline;
        }

        .footer-note {
            text-align: center;
            margin-top: 32px;
            font-size: 13px;
            color: var(--gray-600);
        }

        @media (max-width: 640px) {
            .header-bg {
                padding: 32px 16px 56px 16px;
            }
            .header-bg h1 {
                font-size: 24px;
            }
            .card {
                padding: 20px;
            }
        }
    </style>
</head>
<body>

    <div class="header-bg">
        <div class="brand-badge">🔒 Yaan Privacy</div>
        <h1>Privacy Policy</h1>
        <p>How we collect, store, and protect your data</p>
    </div>

    <div class="container">
        <!-- Switch Tabs -->
        <div class="nav-tabs">
            <a href="/privacy-policy" class="nav-tab active">🔒 Customer Privacy</a>
            <a href="/terms-and-conditions" class="nav-tab">🚚 Customer Terms</a>
            <a href="/vendor/terms-and-conditions" class="nav-tab">🏨 Vendor Terms</a>
            <a href="/vendor/privacy-policy" class="nav-tab">🛡️ Vendor Privacy</a>
        </div>

        <div class="card">
            <!-- Meta information -->
            <div class="meta-box">
                <div class="meta-item"><strong>Effective Date:</strong> {{ $data['effective_date'] }}</div>
                <div class="meta-item"><strong>Entity:</strong> {{ $data['entity'] }}</div>
            </div>

            <!-- Sections -->
            @foreach($data['sections'] as $section)
                <div class="section-item">
                    <h2 class="section-title">{{ $section['title'] }}</h2>
                    <p class="section-text">{{ $section['content'] }}</p>

                    @if(isset($section['items']))
                        <ul class="bullet-list">
                            @foreach($section['items'] as $item)
                                <li>{{ $item }}</li>
                            @endforeach
                        </ul>
                    @endif

                    @if(isset($section['contact']))
                        <div class="contact-box">
                            <h3>Contact Us regarding Privacy</h3>
                            <p style="margin-bottom: 6px;"><strong>Email:</strong> <a href="mailto:info@yaanapp.com">info@yaanapp.com</a> / <a href="mailto:support@yaanapp.com">support@yaanapp.com</a></p>
                            <p style="margin-bottom: 6px;"><strong>Location:</strong> {{ $section['contact']['address'] }}</p>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="footer-note">
            &copy; {{ date('Y') }} Yaan App. All rights reserved. Sole Proprietorship Firm, Gujarat, India.
        </div>
    </div>

</body>
</html>
