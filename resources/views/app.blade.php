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
            type="image/svg+xml"
            sizes="any"
            href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%23eef8ff'/%3E%3Cpath d='M12 26 32 14l20 12-20 12L12 26Z' fill='%230877c9'/%3E%3Cpath d='M20 35v9c7 5 17 5 24 0v-9l-12 7-12-7Z' fill='%230663a8'/%3E%3C/svg%3E"
        >

        @vite('resources/frontend/main.tsx')
    </head>

    <body>
        <div id="app"></div>
    </body>
</html>
