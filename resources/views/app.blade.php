@php
    $seo = $seo ?? \App\Support\Seo::resolve(null);
    $analytics = config('lalogix.analytics');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- SEO (server-rendered, see app/Support/Seo.php) --}}
    <title inertia>{{ $seo['title'] }}</title>
    <meta name="description" content="{{ $seo['description'] }}">
    <meta name="keywords" content="{{ $seo['keywords'] }}">
    <meta name="author" content="LALogix Enterprises">
    <meta name="creator" content="LALogix Enterprises">
    <meta name="publisher" content="LALogix Enterprises">
    @if ($seo['noindex'])
        <meta name="robots" content="noindex, nofollow">
    @else
        <meta name="robots" content="index, follow">
        <meta name="googlebot" content="index, follow, max-video-preview:-1, max-image-preview:large, max-snippet:-1">
    @endif
    <link rel="canonical" href="{{ $seo['canonical'] }}">

    <meta property="og:type" content="{{ $seo['ogType'] }}">
    <meta property="og:locale" content="en_IN">
    <meta property="og:site_name" content="LALogix Enterprises">
    <meta property="og:url" content="{{ $seo['canonical'] }}">
    <meta property="og:title" content="{{ $seo['ogTitle'] }}">
    <meta property="og:description" content="{{ $seo['description'] }}">
    @if ($seo['ogImage'])
        <meta property="og:image" content="{{ $seo['ogImage'] }}">
    @endif

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:site" content="@lalogix">
    <meta name="twitter:creator" content="@lalogix">
    <meta name="twitter:title" content="{{ $seo['ogTitle'] }}">
    <meta name="twitter:description" content="{{ $seo['description'] }}">
    @if ($seo['ogImage'])
        <meta name="twitter:image" content="{{ $seo['ogImage'] }}">
    @endif

    @foreach ($seo['jsonLd'] as $schema)
        <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
    @endforeach

    <link rel="icon" href="/icon.png">
    <link rel="shortcut icon" href="/icon.png">
    <link rel="apple-touch-icon" href="/icon.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    {{-- Analytics (ported from AnalyticsProvider.tsx) --}}
    @if ($analytics['gtm_id'])
        <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer',@json($analytics['gtm_id']));</script>
    @elseif ($analytics['ga_id'])
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ $analytics['ga_id'] }}"></script>
        <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config',@json($analytics['ga_id']),{page_path:window.location.pathname});</script>
    @endif
    @if ($analytics['meta_pixel_id'])
        <script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init',@json($analytics['meta_pixel_id']));fbq('track','PageView');</script>
    @endif

    @viteReactRefresh
    @vite(['resources/js/app.tsx', "resources/js/Pages/{$page['component']}.tsx"])
    @inertiaHead
</head>
<body class="font-sans antialiased">
    @if ($analytics['gtm_id'])
        <noscript><iframe src="https://www.googletagmanager.com/ns.html?id={{ $analytics['gtm_id'] }}" height="0" width="0" style="display:none;visibility:hidden" title="Google Tag Manager"></iframe></noscript>
    @endif
    @inertia
</body>
</html>
