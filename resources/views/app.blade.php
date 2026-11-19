@php
    $seo = $seo ?? \App\Support\Seo::resolve(null);
    $analytics = config('lalogix.analytics');
@endphp
<!DOCTYPE html>
<html lang="en-IN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- Inertia SSR head (when the SSR process runs; it includes the <title>). --}}
    @inertiaHead
    @if (empty($__inertiaSsrResponse))
        <title inertia>{{ $seo['title'] }}</title>
    @endif

    {{-- SEO (server-rendered, see app/Support/Seo.php) --}}
    <meta name="description" content="{{ $seo['description'] }}">
    <meta name="author" content="LALogix Enterprises">
    @if ($seo['noindex'])
        <meta name="robots" content="noindex, nofollow">
    @else
        <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1">
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
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
    @endif

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:site" content="@lalogix">
    <meta name="twitter:title" content="{{ $seo['ogTitle'] }}">
    <meta name="twitter:description" content="{{ $seo['description'] }}">
    @if ($seo['ogImage'])
        <meta name="twitter:image" content="{{ $seo['ogImage'] }}">
    @endif
    <meta name="theme-color" content="#111318">

    @foreach ($seo['jsonLd'] as $schema)
        <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
    @endforeach

    <link rel="icon" href="/favicon-32.png" sizes="32x32" type="image/png">
    <link rel="icon" href="/icon-192.png" sizes="192x192" type="image/png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    {{-- Inter loads without blocking the first paint (system font until it arrives). --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"></noscript>

    {{--
        Analytics. dataLayer always exists so site events (TRACKING.md) queue up;
        the tag scripts load after the page has finished loading so they do not
        compete with the page itself. Queued events are sent once they load.
    --}}
    <script>window.dataLayer=window.dataLayer||[];function lxAfterLoad(f){if(document.readyState==='complete'){setTimeout(f,0)}else{window.addEventListener('load',function(){setTimeout(f,0)})}}</script>
    @if ($analytics['gtm_id'])
        <script>dataLayer.push({'gtm.start':new Date().getTime(),event:'gtm.js'});lxAfterLoad(function(){var j=document.createElement('script');j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+@json($analytics['gtm_id']);document.head.appendChild(j);});</script>
    @elseif ($analytics['ga_id'])
        <script>function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config',@json($analytics['ga_id']),{page_path:window.location.pathname});lxAfterLoad(function(){var j=document.createElement('script');j.async=true;j.src='https://www.googletagmanager.com/gtag/js?id='+@json($analytics['ga_id']);document.head.appendChild(j);});</script>
    @endif
    @if ($analytics['meta_pixel_id'])
        <script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];lxAfterLoad(function(){t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)})}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init',@json($analytics['meta_pixel_id']));fbq('track','PageView');</script>
    @endif

    @viteReactRefresh
    @vite(['resources/js/app.tsx', "resources/js/Pages/{$page['component']}.tsx"])
</head>
<body class="font-sans antialiased">
    @if ($analytics['gtm_id'])
        <noscript><iframe src="https://www.googletagmanager.com/ns.html?id={{ $analytics['gtm_id'] }}" height="0" width="0" style="display:none;visibility:hidden" title="Google Tag Manager"></iframe></noscript>
    @endif
    @inertia
</body>
</html>
