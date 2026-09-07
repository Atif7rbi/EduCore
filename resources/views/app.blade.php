<!DOCTYPE html>
<html lang="ar" dir="rtl">
    <head>
        <meta charset="utf-8">

        <meta
            name="viewport"
            content="width=device-width, initial-scale=1"
        >

        <meta
            name="csrf-token"
            content="{{ csrf_token() }}"
        >

        <title>EduCore</title>
        <link
            rel="icon"
            href="/favicon.svg?v=2"
            type="image/svg+xml"
            sizes="any"
        >
        <link
            rel="shortcut icon"
            href="/favicon.svg?v=2"
            type="image/svg+xml"
        >

        @vite('resources/frontend/main.tsx')
    </head>

    <body>
        <div id="app"></div>
    </body>
</html>
