<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'StudyYodha - AI Home Tutor')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="study-body">
    <div class="study-shell">
        <!-- Sidebar -->
        <aside class="study-sidebar">
            <!-- Logo -->
            <div class="study-logo-wrap">
                <h1 class="study-logo">
                    <a href="/">Study<span>Yodha</span></a>
                </h1>
            </div>

            <!-- Navigation -->
            <nav class="flex-1">
                <ul class="study-nav-list">
                    <li>
                        <a href="/" class="study-nav-link {{ request()->is('/') ? 'is-active' : '' }}">
                            <span class="study-nav-number">01</span>
                            <span>Landing</span>
                        </a>
                    </li>
                    <li>
                        <a href="/auth" class="study-nav-link {{ request()->is('auth') ? 'is-active' : '' }}">
                            <span class="study-nav-number">02</span>
                            <span>Login / signup</span>
                        </a>
                    </li>
                    <li>
                        <a href="/dashboard" class="study-nav-link {{ request()->is('dashboard') ? 'is-active' : '' }}">
                            <span class="study-nav-number">03</span>
                            <span>Student dashboard</span>
                        </a>
                    </li>
                    <li>
                        <a href="/chapters" class="study-nav-link {{ request()->is('chapters') ? 'is-active' : '' }}">
                            <span class="study-nav-number">04</span>
                            <span>Chapter list</span>
                        </a>
                    </li>
                    <li>
                        <a href="/chat" class="study-nav-link {{ request()->is('chat') ? 'is-active' : '' }}">
                            <span class="study-nav-number">05</span>
                            <span>AI tutor chat</span>
                        </a>
                    </li>
                    <li>
                        <a href="/quiz" class="study-nav-link {{ request()->is('quiz') ? 'is-active' : '' }}">
                            <span class="study-nav-number">06</span>
                            <span>Practice quiz</span>
                        </a>
                    </li>
                    <li>
                        <a href="/parent" class="study-nav-link {{ request()->is('parent') ? 'is-active' : '' }}">
                            <span class="study-nav-number">07</span>
                            <span>Parent dashboard</span>
                        </a>
                    </li>
                    <li>
                        <a href="/admin" class="study-nav-link {{ request()->is('admin') ? 'is-active' : '' }}">
                            <span class="study-nav-number">08</span>
                            <span>Admin panel</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="study-main">
            @yield('content')
        </main>
    </div>
</body>
</html>
