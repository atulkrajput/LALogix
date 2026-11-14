<?php

return [
    // Public canonical URL used for SEO tags, sitemap and JSON-LD.
    'site_url' => rtrim(env('SITE_URL', 'https://lalogix.com'), '/'),
    'site_name' => 'LALogix Enterprises',

    // Lead notification e-mails.
    'sales_email' => env('SALES_EMAIL', 'sales@lalogix.com'),
    'email_from' => env('EMAIL_FROM', 'noreply@lalogix.com'),
    'resend_api_key' => env('RESEND_API_KEY'),

    // Folder holding the Markdown insight/blog posts (relative to the project root).
    'blog_path' => env('BLOG_PATH', 'content/blog'),

    'calendly_url' => env('CALENDLY_URL', ''),

    'analytics' => [
        'gtm_id' => env('GTM_ID'),
        'ga_id' => env('GA_ID') ?: 'G-QKHD6CYXBM',
        'meta_pixel_id' => env('META_PIXEL_ID'),
    ],
];
