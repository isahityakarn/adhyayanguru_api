<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class PageController extends Controller
{
    public function landing(): View
    {
        return view('landing');
    }

    public function auth(): View
    {
        return view('auth');
    }

    public function dashboard(): View
    {
        return view('dashboard');
    }

    public function chapters(): View
    {
        return view('chapters');
    }

    public function chat(): View
    {
        return view('chat');
    }

    public function quiz(): View
    {
        return view('quiz');
    }

    public function parent(): View
    {
        return view('parent');
    }

    public function admin(): View
    {
        return view('admin');
    }
}
