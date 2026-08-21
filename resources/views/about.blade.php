<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About Us - Yaan App</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #ea580c;
            --primary-light: #fff7ed;
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
        .footer-note { text-align: center; margin-top: 32px; font-size: 13px; color: var(--gray-600); }
    </style>
</head>
<body>

    <div class="header-bg">
        <div class="brand-badge">🚚 Yaan Platform</div>
        <h1>About Yaan</h1>
        <p>{{ $data['tagline'] }}</p>
    </div>

    <div class="container">
        <div class="card">
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
                </div>
            @endforeach
        </div>

        <div class="footer-note">
            &copy; {{ date('Y') }} Yaan App. All rights reserved. Bharuch, Gujarat, India.
        </div>
    </div>

</body>
</html>
