@extends('layouts.app')

@section('title', 'StudyYodha - AI Home Tutor')

@section('content')
<!-- Header -->
<div class="page-label">
    <p>HOME PAGE</p>
</div>

<!-- Hero Section -->
<div class="hero-panel">
    <!-- Badge -->
    <div class="hero-badge-wrap">
        <span class="hero-badge">
            Class 5-12 • CBSE aligned
        </span>
    </div>

    <!-- Main Heading -->
    <h1 class="hero-title">
        Ek teacher, jo har subject<br>
        padhaye — ghar par, kabhi bhi.
    </h1>

    <!-- Subheading -->
    <p class="hero-copy">
        Doubt-solving, practice tests and step-by-step explanations in Hindi and English, built for Class 5 to 12.
    </p>

    <!-- CTA Buttons -->
    <div class="hero-actions">
        <a href="/auth" class="primary-action">
            Start learning free
        </a>
        <button class="secondary-action">
            See how it works
        </button>
    </div>
</div>

<!-- Subjects Section -->
<div class="subjects-section">
    <h2 class="section-title">Subjects covered</h2>

    <div class="subject-grid">
        <!-- Mathematics Card -->
        <div class="subject-card">
            <p>Class 9-10</p>
            <h3>Mathematics</h3>
        </div>

        <!-- Science Card -->
        <div class="subject-card">
            <p>Class 9-10</p>
            <h3>Science</h3>
        </div>

        <!-- English Card -->
        <div class="subject-card">
            <p>Class 6-12</p>
            <h3>English</h3>
        </div>

        <!-- Accountancy Card -->
        <div class="subject-card">
            <p>Class 11-12</p>
            <h3>Accountancy</h3>
        </div>
    </div>
</div>

<!-- Features Section -->
<div class="mb-12">
    <h2 class="text-3xl font-bold text-gray-800 mb-8">Why StudyYodha?</h2>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="bg-white rounded-xl p-8 border-2 border-gray-200">
            <div class="text-4xl mb-4">🎯</div>
            <h3 class="text-xl font-bold text-gray-800 mb-3">CBSE Aligned</h3>
            <p class="text-gray-600">Complete syllabus coverage for Class 5-12 following CBSE curriculum</p>
        </div>

        <div class="bg-white rounded-xl p-8 border-2 border-gray-200">
            <div class="text-4xl mb-4">🤖</div>
            <h3 class="text-xl font-bold text-gray-800 mb-3">AI-Powered Tutor</h3>
            <p class="text-gray-600">Get instant doubt-solving and personalized explanations 24/7</p>
        </div>

        <div class="bg-white rounded-xl p-8 border-2 border-gray-200">
            <div class="text-4xl mb-4">📚</div>
            <h3 class="text-xl font-bold text-gray-800 mb-3">Bilingual Support</h3>
            <p class="text-gray-600">Learn in Hindi, English, or both - whatever works best for you</p>
        </div>
    </div>
</div>
@endsection
