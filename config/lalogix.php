<?php

/*
|--------------------------------------------------------------------------
| LALogix website content and settings
|--------------------------------------------------------------------------
|
| This is the ONE place for company, contact and product data. The website
| (React, via the shared `site` Inertia prop), the SEO tags, JSON-LD,
| sitemap, policy pages and the chat assistant all read from here, so text
| can be changed without touching components.
|
| Anything that starts with "TODO:" is a fact we do not have yet. While
| `show_todos` is true (default outside production) TODO text is shown on the
| site so it is easy to spot; in production TODO items are hidden or replaced
| by a neutral fallback ("Price on request", ...).
|
| After editing on the server run `php artisan config:clear` (or
| `php artisan optimize`) so a cached config is refreshed.
*/

$wa = '918755587185';

// Product screenshots: public/images/products/{slug}-{1..4}.webp, 1200×750.
// TODO: these are generated placeholders; replace them with real screenshots (same file names).
$shots = fn (string $slug, string $name, array $captions) => array_map(
    fn ($caption, $i) => ['src' => '/images/products/'.$slug.'-'.($i + 1).'.webp', 'alt' => $name.': '.$caption, 'width' => 1200, 'height' => 750],
    $captions, array_keys($captions),
);

return [
    // Public canonical URL used for SEO tags, sitemap and JSON-LD.
    'site_url' => rtrim(env('SITE_URL', 'https://lalogix.com'), '/'),
    'site_name' => 'LALogix Enterprises',

    // Show "TODO:" placeholders on the public site (see the header comment).
    'show_todos' => (bool) env('SHOW_TODOS', env('APP_ENV', 'production') !== 'production'),

    // Lead notification e-mails.
    'sales_email' => env('SALES_EMAIL', 'sales@lalogix.com'),
    'lead_alert_email' => env('LEAD_ALERT_EMAIL', 'info@lalogix.com'),
    'email_from' => env('EMAIL_FROM', 'noreply@lalogix.com'),
    'resend_api_key' => env('RESEND_API_KEY'),

    // Folder holding the Markdown insight/blog posts (relative to the project root).
    'blog_path' => env('BLOG_PATH', 'content/blog'),

    // Optional: when set, /book-demo also shows a Calendly scheduler under the short form.
    'calendly_url' => env('CALENDLY_URL', ''),

    // "Last updated" date shown on the policy pages (content/legal/*.md).
    'legal_updated' => '2026-11-19',

    'analytics' => [
        'gtm_id' => env('GTM_ID'),
        'ga_id' => env('GA_ID') ?: 'G-QKHD6CYXBM',
        'meta_pixel_id' => env('META_PIXEL_ID'),
    ],

    // AI chat widget (proxied to the VIVK API by POST /api/chat; the key never reaches the browser).
    'chat' => [
        'enabled' => (bool) env('CHAT_WIDGET_ENABLED', false),
        'api_url' => env('VIVK_API_URL'),
        'api_key' => env('VIVK_API_KEY'),
        'model' => env('VIVK_API_MODEL', ''),
        'timeout' => (int) env('VIVK_API_TIMEOUT', 20),
        // Ask for name + phone after this many assistant replies.
        'ask_contact_after' => 3,
    ],

    /*
    |----------------------------------------------------------------------
    | Company and contact
    |----------------------------------------------------------------------
    */
    'company' => [
        'name' => 'LALogix Enterprises',
        'short_name' => 'LALogix',
        'legal_form' => 'Sole proprietorship',
        'city' => 'Dehradun',
        'state' => 'Uttarakhand',
        'country' => 'India',
        'street' => 'TODO: street address',
        'email' => 'info@lalogix.com',
        // Voice number. Until it is filled in, call links use the WhatsApp number below.
        'phone' => 'TODO: phone number',
        'whatsapp' => $wa,
        'gstin' => 'TODO: GSTIN',
        'trust_line' => 'GST & MSME registered · Built in Dehradun · 15+ years building software',
        // Hours within which we reply on WhatsApp on working days.
        'reply_hours' => 'TODO',
        'working_days' => 'TODO: working days and hours (e.g. Mon–Sat, 10am–6pm IST)',
        'social' => [
            'LinkedIn' => 'https://linkedin.com/company/lalogix',
            'Twitter' => 'https://twitter.com/lalogix',
            'Facebook' => 'https://facebook.com/lalogix',
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Products (order = order on the site)
    |----------------------------------------------------------------------
    | key           URL slug: /products/{key}
    | lead_key      value stored in leads.product_interest (school/inventory/dairy kept for old leads)
    | status        live | coming_soon
    | billed        billed by LALogix Enterprises (covered by the policy pages)
    | demo          the "Try" button (external link); null = no live demo yet
    */
    'products' => [
        'vivk' => [
            'name' => 'VIVK',
            'brand' => 'VIVK.in',
            'category' => 'AI chat',
            'icon' => 'message-circle',
            'lead_key' => 'vivk',
            'status' => 'live',
            'billed' => true,
            'promise' => 'Your AI assistant: ask anything, get answers in seconds.',
            'bullets' => ['Free to try', 'Small monthly plan', 'Works in the browser'],
            'price_hint' => 'From ₹TODO/month',
            'trial' => 'TODO: free plan / trial terms',
            'demo' => ['label' => 'Try VIVK free', 'url' => 'https://vivk.in'],
            'site_url' => 'https://vivk.in',
            'play_store_url' => null,
            'screenshots' => $shots('vivk', 'VIVK', ['chat screen', 'answer view', 'history', 'plans']),
            'outcomes' => [
                ['title' => 'Answers in seconds', 'text' => 'Ask a question in plain words and get a clear answer straight away.'],
                ['title' => 'Nothing to install', 'text' => 'Open VIVK in any browser on your phone or computer.'],
                ['title' => 'Start free', 'text' => 'Try it free first, then move to a small monthly plan when you need more.'],
            ],
            'features' => [
                ['title' => 'Ask anything', 'text' => 'Type a question the way you would ask a person.'],
                ['title' => 'Browser based', 'text' => 'Works in the browser, no app or download needed.'],
                ['title' => 'Free to try', 'text' => 'Use it before you pay for anything.'],
                ['title' => 'TODO: more VIVK features', 'text' => 'TODO: list the features of the live product.'],
            ],
            'who_for' => ['Students and professionals who want quick answers', 'Shop owners and teams drafting replies and messages', 'TODO: confirm target users'],
            'faq' => [
                ['q' => 'Is VIVK free?', 'a' => 'You can try VIVK free. TODO: describe the free limits and the monthly plan.'],
                ['q' => 'Do I need to install anything?', 'a' => 'No. VIVK works in your web browser.'],
                ['q' => 'Who bills VIVK?', 'a' => 'VIVK subscriptions are billed by LALogix Enterprises, with a GST invoice.'],
            ],
            'whatsapp_text' => "Hi, I'm interested in VIVK (AI chat). Can you tell me more?",
            'seo' => [
                'title' => 'VIVK AI Chat Assistant: Try It Free | LALogix',
                'description' => 'VIVK is an AI chat assistant from LALogix: ask anything in plain words and get answers in seconds. Works in your browser, free to try, small monthly plan.',
            ],
            'schema_category' => 'UtilitiesApplication',
            'os' => 'Web',
        ],

        'amritix' => [
            'name' => 'AMRITIX',
            'brand' => 'AMRITIX',
            'category' => 'Dairy management',
            'icon' => 'milk',
            'lead_key' => 'dairy',
            'status' => 'live',
            'billed' => true,
            'promise' => 'Your whole dairy hisab on your phone.',
            'bullets' => ['Milk purchase & sale', 'Vendors & inventory', 'Income & expense'],
            'price_hint' => 'From ₹TODO/month',
            'trial' => 'TODO: trial length',
            'demo' => ['label' => 'Try demo', 'url' => 'https://amritix.shop'],
            'site_url' => 'https://amritix.shop',
            'play_store_url' => 'TODO: Google Play URL',
            'screenshots' => $shots('amritix', 'AMRITIX', ['milk purchase entry', 'vendor accounts', 'inventory', 'income and expense']),
            'outcomes' => [
                ['title' => 'Every litre accounted for', 'text' => 'Record milk purchase and sale as it happens, so the daily hisab is always ready.'],
                ['title' => 'Vendors and stock in one place', 'text' => 'Keep vendor accounts and inventory together instead of in separate registers.'],
                ['title' => 'Know your income and expense', 'text' => 'See what came in and what went out for each dairy.'],
            ],
            'features' => [
                ['title' => 'Multi-dairy accounts', 'text' => 'Run more than one dairy from a single login.'],
                ['title' => 'Vendors', 'text' => 'Keep each vendor and their account in one place.'],
                ['title' => 'Milk purchase and sale', 'text' => 'Record milk bought and sold, day by day.'],
                ['title' => 'Inventory', 'text' => 'Track the stock your dairy keeps.'],
                ['title' => 'Income and expense', 'text' => 'Log income and expenses for each dairy.'],
                ['title' => 'Android app', 'text' => 'Use AMRITIX on your phone with the Android app, or on the web panel.'],
            ],
            'who_for' => ['Dairy owners who buy milk from vendors and sell it on', 'Families or partners running more than one dairy', 'Anyone who wants the dairy hisab on a phone instead of a register'],
            'faq' => [
                ['q' => 'Is there a mobile app?', 'a' => 'Yes. AMRITIX has an Android app, plus a web panel you can open in any browser.'],
                ['q' => 'Can I manage more than one dairy?', 'a' => 'Yes. AMRITIX supports multiple dairy accounts under one login.'],
                ['q' => 'Can I try it first?', 'a' => 'Yes, open the demo at amritix.shop. TODO: trial length.'],
                ['q' => 'Do I get a GST invoice?', 'a' => 'Yes. AMRITIX is billed by LALogix Enterprises with a GST invoice.'],
            ],
            'whatsapp_text' => "Hi, I'm interested in AMRITIX (dairy management). Can you tell me more?",
            'seo' => [
                'title' => 'AMRITIX Dairy Management App: Milk Hisab | LALogix',
                'description' => 'AMRITIX keeps your dairy hisab on your phone: milk purchase and sale, vendors, inventory, income and expense, across many dairies. Android app and web panel.',
            ],
            'schema_category' => 'BusinessApplication',
            'os' => 'Android, Web',
        ],

        'school-management' => [
            'name' => 'School Automation',
            'brand' => 'TODO: school product brand name',
            'category' => 'School management',
            'icon' => 'graduation-cap',
            'lead_key' => 'school',
            'status' => 'live',
            'billed' => true,
            'promise' => 'Run admissions, attendance and fees without registers.',
            'bullets' => ['Enrollment & attendance', 'Fees & exams', 'Parent communication'],
            'price_hint' => 'From ₹TODO/month',
            'trial' => 'TODO: trial length',
            'demo' => ['label' => 'Try live demo', 'url' => null, 'note' => 'TODO: demo URL and demo login'],
            'site_url' => null,
            'play_store_url' => null,
            'screenshots' => $shots('school-management', 'School Automation', ['admissions', 'attendance', 'fee collection', 'report cards']),
            'outcomes' => [
                ['title' => 'No more registers', 'text' => 'Admissions, attendance and fee records live in one system instead of paper.'],
                ['title' => 'Fees under control', 'text' => 'See who has paid and who has not, and issue receipts without manual work.'],
                ['title' => 'Parents stay informed', 'text' => 'Share attendance, results and notices with parents.'],
            ],
            // TODO: confirm this list matches the live school product.
            'features' => [
                ['title' => 'Student management', 'text' => 'Student profiles with enrollment, personal details and documents.'],
                ['title' => 'Attendance', 'text' => 'Digital attendance with reports for each class.'],
                ['title' => 'Fee management', 'text' => 'Fee structures, collection, receipts and defaulter lists.'],
                ['title' => 'Exams and report cards', 'text' => 'Exam schedules, mark entry, results and report cards.'],
                ['title' => 'Parent communication', 'text' => 'Notices and updates for parents.'],
                ['title' => 'Accounts, library and transport', 'text' => 'Income and expense, book issue and return, and transport routes.'],
            ],
            'who_for' => ['Private schools moving off paper registers', 'School offices that collect fees and issue receipts', 'Principals who want attendance and fee reports at a glance'],
            'faq' => [
                ['q' => 'Can we see it before we decide?', 'a' => 'Yes. Book a demo and we will walk you through it. TODO: self-serve demo login.'],
                ['q' => 'Do we need to install software?', 'a' => 'No. It runs in the browser. TODO: confirm mobile app availability.'],
                ['q' => 'Can you load our existing student data?', 'a' => 'Yes. During setup we load your data with you on a 30-minute call.'],
            ],
            'whatsapp_text' => "Hi, I'm interested in your School Automation software. Can you share more details?",
            'seo' => [
                'title' => 'School Management Software for Indian Schools | LALogix',
                'description' => 'School Automation by LALogix runs admissions, attendance, fees, exams and parent updates without registers. Cloud software for Indian schools, set up in a day.',
            ],
            'schema_category' => 'EducationalApplication',
            'os' => 'Web',
        ],

        'inventory-pos' => [
            'name' => 'Inventory & POS',
            'brand' => 'TODO: inventory product brand name',
            'category' => 'Billing and stock',
            'icon' => 'scan-barcode',
            'lead_key' => 'inventory',
            'status' => 'live',
            'billed' => true,
            'promise' => 'Billing and stock that stay in sync.',
            'bullets' => ['Fast billing', 'Real-time stock', 'Sales reports'],
            'price_hint' => 'From ₹TODO/month',
            'trial' => 'TODO: trial length',
            'demo' => ['label' => 'Try live demo', 'url' => null, 'note' => 'TODO: demo URL and demo login'],
            'site_url' => null,
            'play_store_url' => null,
            'screenshots' => $shots('inventory-pos', 'Inventory & POS', ['billing counter', 'stock list', 'purchases', 'sales report']),
            'outcomes' => [
                ['title' => 'Quick billing at the counter', 'text' => 'Make bills fast and keep the queue moving.'],
                ['title' => 'Stock that is always right', 'text' => 'Every sale and purchase updates stock straight away.'],
                ['title' => 'Know what sells', 'text' => 'Sales reports show your best and slowest items.'],
            ],
            // TODO: confirm this list matches the live inventory product.
            'features' => [
                ['title' => 'POS billing', 'text' => 'Billing with barcode scanning and several payment modes.'],
                ['title' => 'Real-time stock', 'text' => 'Stock levels update with every sale and purchase.'],
                ['title' => 'Purchases and suppliers', 'text' => 'Purchase entries, supplier details and payments.'],
                ['title' => 'Barcodes', 'text' => 'Print and scan barcodes for quick lookup and billing.'],
                ['title' => 'Low-stock alerts', 'text' => 'Get warned before an item runs out.'],
                ['title' => 'Sales reports', 'text' => 'Daily, weekly and monthly sales reports.'],
            ],
            'who_for' => ['Retail shops and showrooms', 'Wholesalers and small warehouses', 'Owners who want billing and stock in one system'],
            'faq' => [
                ['q' => 'Does it print GST bills?', 'a' => 'TODO: confirm GST billing support in the product.'],
                ['q' => 'Can I try it before buying?', 'a' => 'Yes. TODO: demo login. You can also book a free demo.'],
                ['q' => 'Can I use it in more than one shop?', 'a' => 'TODO: confirm multi-location support.'],
            ],
            'whatsapp_text' => "Hi, I'm interested in your Inventory & POS software. Can you share more details?",
            'seo' => [
                'title' => 'Inventory and POS Billing Software for Shops | LALogix',
                'description' => 'Inventory & POS by LALogix keeps billing and stock in sync for shops and warehouses: fast billing, barcodes, real-time stock, purchases and clear sales reports.',
            ],
            'schema_category' => 'BusinessApplication',
            'os' => 'Web',
        ],

        'aisho' => [
            'name' => 'AISHO',
            'brand' => 'AISHO.live',
            'category' => 'TODO: AISHO category',
            'icon' => 'sparkles',
            'lead_key' => 'aisho',
            'status' => 'live',
            'billed' => true,
            'promise' => 'TODO: AISHO promise',
            'bullets' => ['TODO: bullet 1', 'TODO: bullet 2', 'TODO: bullet 3'],
            'price_hint' => 'From ₹TODO/month',
            'trial' => 'TODO: trial length',
            'demo' => ['label' => 'Visit AISHO', 'url' => 'https://aisho.live'],
            'site_url' => 'https://aisho.live',
            'play_store_url' => null,
            'screenshots' => $shots('aisho', 'AISHO', ['screen 1', 'screen 2', 'screen 3', 'screen 4']),
            'outcomes' => [
                ['title' => 'TODO: outcome 1', 'text' => 'TODO'],
                ['title' => 'TODO: outcome 2', 'text' => 'TODO'],
                ['title' => 'TODO: outcome 3', 'text' => 'TODO'],
            ],
            'features' => [
                ['title' => 'TODO: AISHO features', 'text' => 'TODO: describe the live product.'],
            ],
            'who_for' => ['TODO: who AISHO is for'],
            'faq' => [
                ['q' => 'Where can I use AISHO?', 'a' => 'Visit aisho.live. TODO: describe the product.'],
                ['q' => 'Who bills AISHO?', 'a' => 'AISHO is billed by LALogix Enterprises, with a GST invoice.'],
                ['q' => 'Is there a free trial?', 'a' => 'TODO: trial terms.'],
            ],
            'whatsapp_text' => "Hi, I'm interested in AISHO. Can you tell me more?",
            'seo' => [
                'title' => 'AISHO by LALogix: Product Overview and Pricing',
                // TODO: rewrite once the AISHO copy is ready.
                'description' => 'AISHO (aisho.live) is a live product from LALogix Enterprises in Dehradun. See what it does, who it is for and how pricing works. Visit AISHO or book a demo.',
            ],
            'schema_category' => 'BusinessApplication',
            'os' => 'Web',
        ],

        'ayurvedadhara' => [
            'name' => 'AyurvedaDhara',
            'brand' => 'Ayurved Dhara',
            'category' => 'Ayurveda store',
            'icon' => 'leaf',
            'lead_key' => 'ayurvedadhara',
            'status' => 'coming_soon',
            'billed' => false,
            'promise' => "Authentic Ayurvedic products, with a doctor's guidance.",
            'bullets' => ['Ayurvedic medicines & herbs', 'Doctor consultation', 'Doctor-recommended formulas'],
            'price_hint' => 'Coming soon',
            'trial' => null,
            'demo' => null,
            'site_url' => null,
            'play_store_url' => null,
            'screenshots' => $shots('ayurvedadhara', 'AyurvedaDhara', ['store preview', 'product page', 'doctor consultation', 'formulas']),
            'outcomes' => [
                ['title' => 'Authentic products', 'text' => 'Ayurvedic medicines and herbs in one store.'],
                ['title' => "A doctor's guidance", 'text' => 'Consult a doctor before you choose.'],
                ['title' => 'Recommended formulas', 'text' => 'Formulas recommended by doctors.'],
            ],
            'features' => [
                ['title' => 'Ayurvedic medicines and herbs', 'text' => 'A store for authentic Ayurvedic products.'],
                ['title' => 'Doctor consultation', 'text' => 'Talk to a doctor for guidance.'],
                ['title' => 'Doctor-recommended formulas', 'text' => 'Products recommended by doctors.'],
            ],
            'who_for' => ['People looking for authentic Ayurvedic products', 'Anyone who wants a doctor’s advice before buying'],
            'faq' => [
                ['q' => 'When does AyurvedaDhara launch?', 'a' => 'Soon. Leave your number and we will tell you on WhatsApp when it opens. TODO: launch date.'],
                ['q' => 'Will there be doctor consultations?', 'a' => 'Yes, doctor consultation is part of the plan for the store.'],
                ['q' => 'Can I order now?', 'a' => 'Not yet. Use the notify-me form to hear first when it opens.'],
            ],
            'whatsapp_text' => 'Hi, please notify me when AyurvedaDhara launches.',
            'seo' => [
                'title' => 'AyurvedaDhara Ayurveda Store: Coming Soon | LALogix',
                'description' => "AyurvedaDhara is a coming-soon Ayurveda store from LALogix: authentic medicines and herbs with a doctor's guidance. Leave your number to hear when it opens.",
            ],
            'schema_category' => 'ShoppingApplication',
            'os' => 'Web',
        ],

        'doonportal' => [
            'name' => 'DoonPortal',
            'brand' => 'DoonPortal',
            'category' => 'Dehradun directory',
            'icon' => 'map-pin',
            'lead_key' => 'doonportal',
            'status' => 'live',
            'billed' => false,
            'promise' => "Dehradun's local directory: find or list a business free.",
            'bullets' => ['Free listings', 'Claim your business', 'Updated daily'],
            'price_hint' => 'Free listing',
            'trial' => null,
            'demo' => ['label' => 'Visit DoonPortal', 'url' => 'https://doonportal.com'],
            'site_url' => 'https://doonportal.com',
            'play_store_url' => null,
            'screenshots' => $shots('doonportal', 'DoonPortal', ['directory search', 'business listing', 'claim your business', 'categories']),
            'outcomes' => [
                ['title' => 'Get found locally', 'text' => 'List your Dehradun business free so nearby customers can find you.'],
                ['title' => 'Own your listing', 'text' => 'Claim your business and keep its details correct.'],
                ['title' => 'Find what you need', 'text' => 'Search local businesses in Dehradun, updated daily.'],
            ],
            'features' => [
                ['title' => 'Free listings', 'text' => 'Add your business at no cost.'],
                ['title' => 'Claim your business', 'text' => 'Take ownership of an existing listing.'],
                ['title' => 'Updated daily', 'text' => 'The directory is refreshed every day.'],
            ],
            'who_for' => ['Dehradun shops, clinics and service providers', 'People looking for a local business in Dehradun'],
            'faq' => [
                ['q' => 'Is listing free?', 'a' => 'Yes. Listing your business on DoonPortal is free.'],
                ['q' => 'My business is already listed. Can I edit it?', 'a' => 'Yes. Claim your business on DoonPortal to manage its details.'],
                ['q' => 'Which areas are covered?', 'a' => 'DoonPortal is a directory for Dehradun.'],
            ],
            'whatsapp_text' => "Hi, I'd like to list my business on DoonPortal.",
            'seo' => [
                'title' => 'DoonPortal: Free Dehradun Business Directory | LALogix',
                'description' => "DoonPortal is Dehradun's local business directory from LALogix. Find shops and services near you, or list and claim your own business free. Updated daily.",
            ],
            'schema_category' => 'TravelApplication',
            'os' => 'Web',
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Homepage
    |----------------------------------------------------------------------
    */
    'home' => [
        'hero' => [
            'title' => 'Ready-to-use software for schools, dairies and shops. Live today, not someday.',
            'subtitle' => 'LALogix builds cloud apps that Indian businesses switch on in a day: AI chat, dairy accounts, school management and billing. Try any product free before you talk to us.',
        ],
        'how_it_works' => [
            ['title' => 'Try it free', 'text' => 'Open the live demo or start a free trial.'],
            ['title' => 'We set it up with you', 'text' => 'A 30-minute call to load your data.'],
            ['title' => 'Run your business from your phone', 'text' => 'Web panel plus Android app where available.'],
        ],
        'why' => [
            ['icon' => 'package-check', 'title' => 'Products, not projects', 'text' => 'Every app is already built and running.'],
            ['icon' => 'indian-rupee', 'title' => 'Made for Indian businesses', 'text' => 'Rupee pricing, GST invoices, WhatsApp support.'],
            ['icon' => 'receipt', 'title' => 'One team, one bill', 'text' => 'All products billed by LALogix Enterprises.'],
            ['icon' => 'message-square-reply', 'title' => 'Support that replies', 'text' => 'WhatsApp support within TODO hours on working days.'],
        ],
        // Proof section: hidden until there is real data. Only non-empty items render.
        'proof' => [
            'counters' => [
                // ['value' => '120', 'label' => 'Schools using LALogix'],
            ],
            'testimonials' => [
                // ['quote' => '...', 'name' => '...', 'role' => '...', 'product' => 'amritix'],
            ],
        ],
        'faq' => [
            ['q' => 'Is there a free trial?', 'a' => 'TODO: free trial terms for each product.'],
            ['q' => 'Do I need to install anything?', 'a' => 'No. Every product runs in the browser. AMRITIX also has an Android app.'],
            ['q' => 'Is my data safe?', 'a' => 'Each client gets a separate account, so your data is never mixed with anyone else’s. TODO: backup schedule.'],
            ['q' => 'Do you give GST invoices?', 'a' => 'Yes. All products are billed by LALogix Enterprises with a GST invoice.'],
            ['q' => 'Can the software be customised?', 'a' => 'Yes, through settings in each product. TODO: paid add-ons.'],
        ],
        'final_cta' => [
            'title' => 'See your business on LALogix in 15 minutes.',
            'reply_line' => 'We reply on WhatsApp within TODO hours.',
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | "Find your solution" quiz
    |----------------------------------------------------------------------
    | Rules are checked top to bottom; the first match wins. A rule matches
    | when every key it names equals the answer. `fallback` is used otherwise.
    */
    'quiz' => [
        'business_types' => ['School', 'Dairy', 'Shop or warehouse', 'Clinic or wellness', 'Other'],
        'sizes' => ['Under 50', '50–500', '500+'],
        'headaches' => ['Accounts', 'Attendance or records', 'Stock', 'Customer replies'],
        'rules' => [
            ['business_type' => 'School', 'product' => 'school-management'],
            ['business_type' => 'Dairy', 'product' => 'amritix'],
            ['business_type' => 'Shop or warehouse', 'product' => 'inventory-pos'],
            ['headache' => 'Customer replies', 'product' => 'vivk'],
            ['headache' => 'Stock', 'product' => 'inventory-pos'],
            ['headache' => 'Accounts', 'product' => 'inventory-pos'],
            ['headache' => 'Attendance or records', 'product' => 'school-management'],
        ],
        'fallback' => 'vivk',
    ],

    // Industries page: which product fits which kind of business.
    'industries' => [
        ['icon' => 'graduation-cap', 'title' => 'Schools', 'text' => 'Admissions, attendance, fees, exams and parent updates without registers.', 'products' => ['school-management']],
        ['icon' => 'milk', 'title' => 'Dairies', 'text' => 'Milk purchase and sale, vendors, inventory, income and expense on your phone.', 'products' => ['amritix']],
        ['icon' => 'store', 'title' => 'Shops and warehouses', 'text' => 'Billing and stock that stay in sync, with sales reports.', 'products' => ['inventory-pos']],
        ['icon' => 'message-circle', 'title' => 'Any business that answers customers', 'text' => 'An AI assistant that drafts answers and replies in seconds.', 'products' => ['vivk']],
        ['icon' => 'leaf', 'title' => 'Ayurveda and wellness', 'text' => "Authentic Ayurvedic products with a doctor's guidance (coming soon).", 'products' => ['ayurvedadhara']],
        ['icon' => 'map-pin', 'title' => 'Local businesses in Dehradun', 'text' => 'Get listed free in the local directory.', 'products' => ['doonportal']],
    ],

    // Exit-intent popup (desktop only, once per visitor). TODO: must match the real trial terms.
    'exit_offer' => [
        'title' => 'Before you go: try LALogix free',
        'text' => 'TODO: exit offer text (must match the real trial terms).',
    ],
];
