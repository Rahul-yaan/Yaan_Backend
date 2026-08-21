<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class LegalController extends Controller
{
    /**
     * Build plain text from structured data.
     */
    private function buildPlainText(array $data): string
    {
        $text = $data['title'] . "\n";
        $text .= "Effective Date: " . ($data['effective_date'] ?? 'August 21, 2026') . "\n\n";

        if (!empty($data['sections']) && is_array($data['sections'])) {
            foreach ($data['sections'] as $sec) {
                $text .= ($sec['title'] ?? '') . "\n";
                $text .= ($sec['content'] ?? '') . "\n";
                if (!empty($sec['items']) && is_array($sec['items'])) {
                    foreach ($sec['items'] as $item) {
                        $text .= " • " . $item . "\n";
                    }
                }
                if (!empty($sec['footer'])) {
                    $text .= $sec['footer'] . "\n";
                }
                $text .= "\n";
            }
        }
        return trim($text);
    }

    /**
     * Build HTML string from structured data.
     */
    private function buildHtmlText(array $data): string
    {
        $html = "<div style='font-family: sans-serif; line-height: 1.6;'>";
        $html .= "<h1>" . e($data['title'] ?? '') . "</h1>";
        $html .= "<p><strong>Effective Date:</strong> " . e($data['effective_date'] ?? 'August 21, 2026') . "</p><hr>";

        if (!empty($data['sections']) && is_array($data['sections'])) {
            foreach ($data['sections'] as $sec) {
                $html .= "<h2 style='color: #0f172a; margin-top: 20px;'>" . e($sec['title'] ?? '') . "</h2>";
                $html .= "<p style='color: #334155;'>" . e($sec['content'] ?? '') . "</p>";
                if (!empty($sec['items']) && is_array($sec['items'])) {
                    $html .= "<ul>";
                    foreach ($sec['items'] as $item) {
                        $html .= "<li style='margin-bottom: 6px;'>" . e($item) . "</li>";
                    }
                    $html .= "</ul>";
                }
                if (!empty($sec['footer'])) {
                    $html .= "<p style='color: #334155;'>" . e($sec['footer']) . "</p>";
                }
            }
        }
        $html .= "</div>";
        return $html;
    }

    /**
     * Get Customer Terms and Conditions data.
     */
    private function getTermsData(): array
    {
        return [
            'title' => 'Terms & Conditions for Customers',
            'app_name' => 'Yaan',
            'effective_date' => 'August 21, 2026',
            'entity' => 'Yaan (Sole Proprietorship Firm, Gujarat, India)',
            'sections' => [
                [
                    'id' => 1,
                    'title' => '1. Acceptance of Terms',
                    'content' => 'By downloading, accessing, or using the Yaan App, you acknowledge that you have read, understood, and agree to be bound by these Terms and Conditions, as well as our Privacy Policy. If you do not agree to these terms, please do not use the App.'
                ],
                [
                    'id' => 2,
                    'title' => '2. Services Provided',
                    'content' => 'Yaan enables users to locate and book overnight truck parking spaces at registered hotels and dhabas listed on our platform. These listings may also include complimentary breakfast, restrooms, or other amenities as offered by the respective partner.'
                ],
                [
                    'id' => 3,
                    'title' => '3. User Registration and Information',
                    'content' => 'To use the App, users must register by providing certain personal and vehicle-related information, including:',
                    'items' => [
                        'Name',
                        'Email ID',
                        'Phone Number',
                        'Truck Number',
                        'Logistics Company Name',
                        'Vehicle Wheel Type (4-wheel, 6-wheel, etc.)'
                    ],
                    'footer' => 'You agree to provide accurate and up-to-date information. Any consequences arising from false or outdated information are solely your responsibility.'
                ],
                [
                    'id' => 4,
                    'title' => '4. Booking Process',
                    'content' => 'Users can search for and book parking at partner hotels via the App. All bookings are subject to availability. Once a booking is confirmed, an invoice will be sent via email within 48 hours. Yaan is not responsible for any errors in distance or location displayed in the App.'
                ],
                [
                    'id' => 5,
                    'title' => '5. Role of Yaan',
                    'content' => 'Yaan acts solely as a technology platform connecting users with hotels that provide truck parking facilities. Yaan does not own or operate any hotels, dhabas, or parking locations. All responsibilities regarding services, breakfast, security, cleanliness, and facilities rest solely with the hotel owners.'
                ],
                [
                    'id' => 6,
                    'title' => '6. Limitation of Liability',
                    'content' => 'Yaan shall not be held liable for any damage, theft, or incident involving vehicles, cargo, or personal property occurring during transit or while parked at the listed locations. Users agree that Yaan bears no responsibility for any actions or inactions of the partner hotels or their staff.'
                ],
                [
                    'id' => 7,
                    'title' => '7. Cancellation and Refund Policy',
                    'content' => 'Yaan does not offer cancellations or refunds once a booking is made and payment is processed. Please review your booking details carefully before confirming.'
                ],
                [
                    'id' => 8,
                    'title' => '8. Payments',
                    'content' => 'All payments are to be made through the App at the time of booking. Parking rates are set by the hotel and may vary based on vehicle specifications.'
                ],
                [
                    'id' => 9,
                    'title' => '9. User Conduct',
                    'content' => 'Users must not:',
                    'items' => [
                        'Interfere with the proper working of the App.',
                        'Violate any laws while using the App.',
                        'Misuse or tamper with App content, listings, or operations.'
                    ],
                    'footer' => 'Any violation may lead to suspension or permanent blocking of access to the App.'
                ],
                [
                    'id' => 10,
                    'title' => '10. Intellectual Property',
                    'content' => 'All trademarks, logos, content, and software used in the App are owned by or licensed to Yaan. Users are prohibited from copying, reproducing, or distributing any part of the App without written permission.'
                ],
                [
                    'id' => 11,
                    'title' => '11. Indemnification',
                    'content' => 'You agree to indemnify, defend, and hold harmless Yaan and its affiliates from any claims, liabilities, damages, or expenses (including legal fees) arising from your use or misuse of the App, or your breach of these Terms.'
                ],
                [
                    'id' => 12,
                    'title' => '12. Governing Law and Jurisdiction',
                    'content' => 'These Terms shall be governed by and construed in accordance with the laws of the State of Gujarat, India. Any disputes arising from or related to the use of the App shall be subject to the exclusive jurisdiction of the courts in Bharuch, Gujarat.'
                ],
                [
                    'id' => 13,
                    'title' => '13. Changes to Terms',
                    'content' => 'Yaan reserves the right to modify these Terms at any time. Updates will be posted in the App, and continued usage of the App constitutes acceptance of the updated Terms.'
                ],
                [
                    'id' => 14,
                    'title' => '14. Contact Information',
                    'content' => 'If you have any questions or concerns about these Terms, please contact us:',
                    'contact' => [
                        'emails' => ['info@yaanapp.com', 'support@yaanapp.com'],
                        'address' => 'Bharuch, Gujarat, India',
                    ]
                ]
            ]
        ];
    }

    /**
     * Get Customer Privacy Policy data.
     */
    private function getPrivacyData(): array
    {
        return [
            'title' => 'Privacy Policy',
            'app_name' => 'Yaan',
            'effective_date' => 'August 21, 2026',
            'entity' => 'Yaan (Gujarat, India)',
            'sections' => [
                [
                    'id' => 1,
                    'title' => '1. Information We Collect',
                    'content' => 'We collect the following personal and vehicle-related information when you register or use the App:',
                    'items' => [
                        'Name',
                        'Email ID',
                        'Phone Number',
                        'Truck Number',
                        'Logistics Company Name',
                        'Wheel Type'
                    ]
                ],
                [
                    'id' => 2,
                    'title' => '2. Use of Information',
                    'content' => 'We use your information for the following purposes:',
                    'items' => [
                        'To create and manage your account',
                        'To process bookings',
                        'To send invoices and confirmations',
                        'To improve our App and services',
                        'To contact you for customer support or important updates'
                    ]
                ],
                [
                    'id' => 3,
                    'title' => '3. Data Storage and Security',
                    'content' => 'Your personal data is stored securely and is not shared with third parties except with hotels where bookings are made. We take reasonable measures to protect your information against unauthorized access or loss.'
                ],
                [
                    'id' => 4,
                    'title' => '4. No Data Sharing for Marketing',
                    'content' => 'We do not sell or rent your data to third parties for marketing purposes.'
                ],
                [
                    'id' => 5,
                    'title' => '5. Cookies and Analytics',
                    'content' => 'We do not currently use cookies or tracking pixels in the App.'
                ],
                [
                    'id' => 6,
                    'title' => '6. Data Retention',
                    'content' => 'We retain your data only as long as necessary for legal, business, or operational reasons.'
                ],
                [
                    'id' => 7,
                    'title' => '7. Children’s Privacy',
                    'content' => 'Our services are not intended for individuals under the age of 18.'
                ],
                [
                    'id' => 8,
                    'title' => '8. Changes to Privacy Policy',
                    'content' => 'We reserve the right to update this Privacy Policy at any time. Changes will be notified via the App. Continued use after changes constitutes acceptance of the new policy.'
                ],
                [
                    'id' => 9,
                    'title' => '9. Disclaimer',
                    'content' => 'Yaan shall not be liable for any breach of data caused by third-party attacks, unauthorized access, or force majeure events.'
                ],
                [
                    'id' => 10,
                    'title' => '10. Contact Us',
                    'content' => 'If you have questions about this Privacy Policy, please contact us:',
                    'contact' => [
                        'emails' => ['info@yaanapp.com', 'support@yaanapp.com'],
                        'address' => 'Bharuch, Gujarat, India',
                    ]
                ]
            ]
        ];
    }

    /**
     * Get Vendor / Partner Terms and Conditions data.
     */
    private function getVendorTermsData(): array
    {
        return [
            'title' => 'Terms & Conditions (Vendor / Hotel Partner App)',
            'app_name' => 'Yaan Partner',
            'effective_date' => 'August 21, 2026',
            'entity' => 'Yaan (Sole Proprietorship Firm, Gujarat, India)',
            'target_audience' => 'Hotel & Dhaba Partners',
            'sections' => [
                [
                    'id' => 1,
                    'title' => '1. Registration and Fees',
                    'content' => 'These rules govern your registration and fee structure as a Yaan Hotel/Dhaba Partner:',
                    'items' => [
                        'Registration Process: To join the Yaan platform, each Partner must complete the registration process and submit the required documents.',
                        'One-Time Registration Fee: The Partner must pay a one-time registration fee of INR 130, which is non-refundable.',
                        'Initial Fixed Fee: For the first 5 months after registration, Yaan charges a fixed fee of INR 40 per booking. After this period, the Partner is free to set their own pricing, subject to this Agreement.'
                    ]
                ],
                [
                    'id' => 2,
                    'title' => '2. Documentation Requirements',
                    'content' => 'Partners must fulfill documentation and compliance standards:',
                    'items' => [
                        'Mandatory Documents: Partners must provide valid copies of the FSSAI certificate, GST registration details, and other relevant documents as required by Yaan to ensure compliance with Indian regulations.',
                        'Address Verification: The address provided during registration must match the address on official documents (excluding PAN and Aadhaar).',
                        'Failure to Comply: If the Partner fails to file GST returns for four consecutive months, the Partner’s hotel will be hidden from the platform (banned from listings). However, the partnership itself will not be terminated.'
                    ]
                ],
                [
                    'id' => 3,
                    'title' => '3. GST Filing and Payment Release',
                    'content' => 'Payment disbursement schedules and GST compliance guidelines:',
                    'items' => [
                        'GST Filing: Partners must file their GST returns within the 1st to 5th of each month to ensure timely processing of payments.',
                        'Verification Process: Yaan will verify that GST returns are filed before releasing the Partner’s payment. If the GST status is not updated, payments will be delayed.',
                        'Payment Schedule: Yaan will release payments between the 8th and 10th of the month following the service (e.g., January services paid between Feb 8th–10th).',
                        'Consolidated Invoice: The Partner must submit a monthly consolidated invoice, which Yaan cross-checks with platform records before processing.'
                    ]
                ],
                [
                    'id' => 4,
                    'title' => '4. Commission and Payments',
                    'content' => 'Commission rates and pricing flexibility:',
                    'items' => [
                        'Commission: Yaan charges a 20% commission on gross revenue generated through the platform, plus an additional 18% GST on the commission amount.',
                        'Setting Rates: Partners can set rates based on truck types (e.g., 4-wheel, 6-wheel, 8-wheel). The minimum booking rate is INR 40.',
                        'Additional Charges: If parking stays include other services (e.g., meals), the Partner is responsible for including those in the price.'
                    ]
                ],
                [
                    'id' => 5,
                    'title' => '5. Responsibilities of the Partner',
                    'content' => 'On-site responsibilities and driver check-in protocols:',
                    'items' => [
                        'Liability: Once a truck enters the Partner’s premises, the Partner assumes full responsibility for the truck, driver, and associated vehicles. Yaan is not liable for accidents, thefts, or damages.',
                        'Amenities: Partners must provide basic breakfast for one person per booking (meal details at hotel discretion). Additional services (lunch, dinner, showers) are optional.',
                        'Verification: Yaan shares driver name, phone number, and vehicle details with the Partner. The Partner must verify these details at check-in.'
                    ]
                ],
                [
                    'id' => 6,
                    'title' => '6. Exclusivity',
                    'content' => 'Exclusive platform commitment:',
                    'items' => [
                        'Exclusive Agreement: Partners agree to work exclusively with Yaan and not partner with competing truck parking platforms.',
                        'Termination: Upon termination, Partners must settle all outstanding taxes/dues with Yaan and are prohibited from joining a competitor platform for one year.'
                    ]
                ],
                [
                    'id' => 7,
                    'title' => '7. Marketing Requirements',
                    'content' => 'Promotional display and marketing support:',
                    'items' => [
                        'Promotional Materials: Yaan provides banners, posters, and digital assets. Partners agree to display these prominently on property.',
                        'Support for Marketing: Yaan assists in promoting partner parking spaces across online and offline channels.'
                    ]
                ],
                [
                    'id' => 8,
                    'title' => '8. Linked Sites',
                    'content' => 'Third-party links on the platform do not constitute endorsement or control. Yaan assumes no responsibility for external content or privacy practices.'
                ],
                [
                    'id' => 9,
                    'title' => '9. Forward-Looking Statements',
                    'content' => 'Platform information is based on publication date. Yaan is under no obligation to update content regularly unless explicitly stated.'
                ],
                [
                    'id' => 10,
                    'title' => '10. Disclaimer of Warranties and Limitation of Liability',
                    'content' => 'The platform is provided "as is" without warranties. Yaan does not guarantee uninterrupted service and is not liable for indirect or consequential damages.'
                ],
                [
                    'id' => 11,
                    'title' => '11. Exclusions and Limitations',
                    'content' => 'Yaan’s liability is limited to the maximum extent permitted under applicable law, excluding loss of profit, revenue, or goodwill.'
                ],
                [
                    'id' => 12,
                    'title' => '12. Proprietary Rights',
                    'content' => 'All trademarks, logos, and content are owned by Yaan. Partners receive a limited, non-exclusive license to use materials solely for platform promotion.'
                ],
                [
                    'id' => 13,
                    'title' => '13. Indemnity',
                    'content' => 'Partners agree to indemnify and hold harmless Yaan against any claims, losses, or legal expenses arising from platform use or breach of terms.'
                ],
                [
                    'id' => 14,
                    'title' => '14. Copyright and Trademark',
                    'content' => 'All platform content is protected by copyright and trademark laws. Unauthorized reproduction or use is strictly prohibited.'
                ],
                [
                    'id' => 15,
                    'title' => '15. Intellectual Property Infringement',
                    'content' => 'Yaan respects intellectual property rights and responds promptly to valid infringement claims submitted to support.'
                ],
                [
                    'id' => 16,
                    'title' => '16. Place of Performance',
                    'content' => 'Services are managed and operated from India. Yaan makes no warranty regarding service availability outside India.'
                ],
                [
                    'id' => 17,
                    'title' => '17. General',
                    'content' => 'Severability applies to invalid provisions. Failure to enforce any term is not a waiver. These terms supersede prior agreements.'
                ],
                [
                    'id' => 18,
                    'title' => '18. Privacy Policy Modifications',
                    'content' => 'Yaan reserves the right to modify terms and privacy policies at any time. Updates will be posted with the last modified date.',
                    'contact' => [
                        'emails' => ['info@yaanapp.com', 'support@yaanapp.com'],
                        'address' => 'Bharuch, Gujarat, India'
                    ]
                ]
            ]
        ];
    }

    /**
     * Get Vendor / Partner Privacy Policy data.
     */
    private function getVendorPrivacyData(): array
    {
        return [
            'title' => 'Privacy Policy (Vendor / Hotel Partner App)',
            'app_name' => 'Yaan Partner',
            'effective_date' => 'August 21, 2026',
            'entity' => 'Yaan (Sole Proprietorship Firm, Gujarat, India)',
            'target_audience' => 'Hotel & Dhaba Partners',
            'sections' => [
                [
                    'id' => 1,
                    'title' => '1. Information We Collect',
                    'content' => 'We collect the following information from our hotel/dhaba partners:',
                    'items' => [
                        'Registration Information: Name, address, contact details (email, phone), hotel/dhaba details (location, parking capacity, amenities), and legal documents (FSSAI, GST).',
                        'Booking Information: Details of bookings, including truck registration numbers, driver details, and services utilized.',
                        'Payment Information: Bank account or payment gateway details necessary for processing disbursements.',
                        'Other Information: Additional data provided voluntarily for marketing, feedback, or support.'
                    ]
                ],
                [
                    'id' => 2,
                    'title' => '2. Use of Information',
                    'content' => 'We use partner data for the following purposes:',
                    'items' => [
                        'To facilitate registration, listing, and booking communications between truck drivers and partners.',
                        'To process payment disbursements for services rendered through Yaan.',
                        'To send promotional and platform operational updates.',
                        'To provide customer support and improve overall service quality.'
                    ]
                ],
                [
                    'id' => 3,
                    'title' => '3. Sharing of Information',
                    'content' => 'We do not sell or share personal information with third parties for marketing purposes. Data is shared only under strict operational conditions:',
                    'items' => [
                        'With trusted service providers assisting in payment processing and customer support.',
                        'To comply with legal obligations, tax filings, or government directives.'
                    ]
                ],
                [
                    'id' => 4,
                    'title' => '4. Data Security',
                    'content' => 'We implement technical and organizational measures to safeguard partner data against unauthorized access, loss, or alteration.'
                ],
                [
                    'id' => 5,
                    'title' => '5. Data Retention',
                    'content' => 'Partner data is retained only as long as required to fulfill legal, tax, accounting, or business reporting obligations.'
                ],
                [
                    'id' => 6,
                    'title' => '6. Partner Rights',
                    'content' => 'Partners hold the following data rights:',
                    'items' => [
                        'Access: Request a copy of personal data held by Yaan.',
                        'Rectification: Request correction of inaccurate information.',
                        'Erasure: Request deletion of data subject to legal and tax retention requirements.',
                        'Opt-Out: Unsubscribe from non-essential promotional communications.'
                    ]
                ],
                [
                    'id' => 7,
                    'title' => '7. Cookies and Tracking Technologies',
                    'content' => 'We may use cookies and tracking technologies to analyze platform patterns and improve the partner experience.'
                ],
                [
                    'id' => 8,
                    'title' => '8. Changes to Privacy Policy',
                    'content' => 'We reserve the right to update this policy. Revisions will be posted on this page with an updated effective date.'
                ],
                [
                    'id' => 9,
                    'title' => '9. Contact Information',
                    'content' => 'If you have questions regarding this Vendor Privacy Policy, please reach out to us:',
                    'contact' => [
                        'emails' => ['info@yaanapp.com', 'support@yaanapp.com'],
                        'address' => 'Yaan - Bharuch, Gujarat, India'
                    ]
                ]
            ]
        ];
    }

    // ============================================================
    // WEB VIEWS
    // ============================================================

    public function termsView()
    {
        $data = $this->getTermsData();
        return view('terms', compact('data'));
    }

    public function privacyView()
    {
        $data = $this->getPrivacyData();
        return view('privacy', compact('data'));
    }

    public function vendorTermsView()
    {
        $data = $this->getVendorTermsData();
        return view('vendor_terms', compact('data'));
    }

    public function vendorPrivacyView()
    {
        $data = $this->getVendorPrivacyData();
        return view('vendor_privacy', compact('data'));
    }

    // ============================================================
    // UNIVERSAL JSON API RESPONSES
    // Supports all mobile app Flutter JSON parsers:
    // - content (String)
    // - html_content (HTML String)
    // - terms / privacy (String)
    // - sections (List<Map>)
    // - data (Object / List)
    // ============================================================

    public function termsJson(): JsonResponse
    {
        $raw   = $this->getTermsData();
        $plain = $this->buildPlainText($raw);
        $html  = $this->buildHtmlText($raw);

        $payload = array_merge($raw, [
            'content'      => $plain,
            'html_content' => $html,
            'terms'        => $plain,
            'url'          => url('/terms-and-conditions'),
        ]);

        return response()->json([
            'success'      => true,
            'status'       => true,
            'title'        => $raw['title'],
            'content'      => $plain,
            'html_content' => $html,
            'terms'        => $plain,
            'url'          => url('/terms-and-conditions'),
            'sections'     => $raw['sections'],
            'data'         => $payload,
        ]);
    }

    public function privacyJson(): JsonResponse
    {
        $raw   = $this->getPrivacyData();
        $plain = $this->buildPlainText($raw);
        $html  = $this->buildHtmlText($raw);

        $payload = array_merge($raw, [
            'content'      => $plain,
            'html_content' => $html,
            'privacy'      => $plain,
            'url'          => url('/privacy-policy'),
        ]);

        return response()->json([
            'success'      => true,
            'status'       => true,
            'title'        => $raw['title'],
            'content'      => $plain,
            'html_content' => $html,
            'privacy'      => $plain,
            'url'          => url('/privacy-policy'),
            'sections'     => $raw['sections'],
            'data'         => $payload,
        ]);
    }

    public function vendorTermsJson(): JsonResponse
    {
        $raw   = $this->getVendorTermsData();
        $plain = $this->buildPlainText($raw);
        $html  = $this->buildHtmlText($raw);

        $payload = array_merge($raw, [
            'content'      => $plain,
            'html_content' => $html,
            'terms'        => $plain,
            'url'          => url('/vendor/terms-and-conditions'),
        ]);

        return response()->json([
            'success'      => true,
            'status'       => true,
            'title'        => $raw['title'],
            'content'      => $plain,
            'html_content' => $html,
            'terms'        => $plain,
            'url'          => url('/vendor/terms-and-conditions'),
            'sections'     => $raw['sections'],
            'data'         => $payload,
        ]);
    }

    public function vendorPrivacyJson(): JsonResponse
    {
        $raw   = $this->getVendorPrivacyData();
        $plain = $this->buildPlainText($raw);
        $html  = $this->buildHtmlText($raw);

        $payload = array_merge($raw, [
            'content'      => $plain,
            'html_content' => $html,
            'privacy'      => $plain,
            'url'          => url('/vendor/privacy-policy'),
        ]);

        return response()->json([
            'success'      => true,
            'status'       => true,
            'title'        => $raw['title'],
            'content'      => $plain,
            'html_content' => $html,
            'privacy'      => $plain,
            'url'          => url('/vendor/privacy-policy'),
            'sections'     => $raw['sections'],
            'data'         => $payload,
        ]);
    }
}
