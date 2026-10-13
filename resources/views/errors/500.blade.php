{{--
    Deliberately self-contained: the application layout reads the database for
    the bar and the footer, and a 500 is exactly the moment the database may be
    what is wrong. Nothing here needs anything but the file itself.
--}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('sanabel.errors.500_title') }}</title>
    {{-- The palette still comes from config/brand.php: it is read from a file
         and needs nothing that may be the thing that is broken. --}}
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center;
               background: {{ config('brand.colors.surface') }};
               color: {{ config('brand.colors.ink') }};
               font-family: Tahoma, Arial, sans-serif; }
        .box { max-width: 32rem; padding: 2.5rem; text-align: center; }
        h1 { font-size: 1.25rem; margin: 0 0 .75rem; }
        p { opacity: .75; line-height: 1.8; margin: 0 0 1.5rem; }
        a { display: inline-block; padding: .6rem 1.4rem; border-radius: .75rem;
            background: {{ config('brand.colors.primary') }}; color: #fff; text-decoration: none; }
    </style>
</head>
<body>
    <div class="box">
        <h1>{{ __('sanabel.errors.500_title') }}</h1>
        <p>{{ __('sanabel.errors.500_body') }}</p>
        <a href="/">{{ __('sanabel.errors.home') }}</a>
    </div>
</body>
</html>
