{{-- Photobook PDF cover layout --}}

@php
    $opts = isset($options) && is_array($options) ? $options : [];

    $title = trim((string)($opts['title'] ?? '')) !== ''
        ? (string) $opts['title']
        : (trim((string)($opts['cover_title'] ?? '')) !== ''
            ? (string) $opts['cover_title']
            : (string) config('photobook.cover.title'));

    $subtitle = trim((string)($opts['cover_subtitle'] ?? '')) !== ''
        ? (string) $opts['cover_subtitle']
        : (trim((string)($opts['subtitle'] ?? '')) !== ''
            ? (string) $opts['subtitle']
            : (string) config('photobook.cover.subtitle'));

    $showDate = array_key_exists('cover_show_date', $opts)
        ? (bool) $opts['cover_show_date']
        : (bool) config('photobook.cover.show_date');

    $dateCandidate = $opts['cover_date'] ?? $opts['date'] ?? null;
    $dateText = $showDate
        ? (trim((string) $dateCandidate) !== '' ? (string) $dateCandidate : now()->format('F Y'))
        : null;

    $imgSrc = $opts['cover_image_pdf']
        ?? $opts['cover_image_src']
        ?? $opts['cover_image_web']
        ?? null;

    $bgUrl = $imgSrc ? '/' . ltrim($imgSrc, '/') : null;
    $bgStyle = $bgUrl ? "background-image:url('" . e($bgUrl) . "');" : '';
    $coverClass = $imgSrc ? 'cover-top has-photo' : 'cover-top cover-placeholder';
    $coverStyleAttr = $bgStyle ? ' style="' . $bgStyle . '"' : '';

    $fontFamilyResolved = isset($fontFamily) && is_string($fontFamily) && trim($fontFamily) !== ''
        ? trim($fontFamily)
        : 'Inter';
@endphp

<div class="page cover-page">
    <div class="{{ $coverClass }}"{!! $coverStyleAttr !!}>
        @if(!$imgSrc)
            <span>No cover photo selected</span>
        @endif
        @if($imgSrc && $title)
            <div class="cover-title-overlay">{{ $title }}</div>
        @endif
    </div>

    <div class="cover-bottom">
        @if($title)
            <div class="cover-title">{{ $title }}</div>
        @endif
        @if($subtitle)
            <div class="cover-subtitle">{{ $subtitle }}</div>
        @endif
        @if($dateText)
            <div class="cover-date">{{ $dateText }}</div>
        @endif
    </div>
</div>

<style>
.cover-page {
    position: relative;
    width: 100%;
    height: 100%;
    font-family: "{{ e($fontFamilyResolved) }}", "Helvetica Neue", sans-serif;
    color: #111827;
}

.cover-top {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 35.1%;
    overflow: hidden;
    background: #111827;
}

.cover-top.cover-placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    color: #f9fafb;
    font-size: 18px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.cover-top.has-photo {
    background-size: cover;
    background-position: center center;
    background-repeat: no-repeat;
}

.cover-title-overlay {
    position: absolute;
    left: 7%;
    bottom: 12%;
    padding: 8px 18px;
    color: #f9fafb;
    font-size: 34px;
    font-weight: 600;
    letter-spacing: 0.02em;
    text-shadow: 0 4px 16px rgba(0, 0, 0, 0.45);
}

.cover-bottom {
    position: absolute;
    left: 0;
    right: 0;
    top: 64.9%;
    bottom: 0;
    padding: 36px 48px;
    background: #ffffff;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 12px;
}

.cover-title {
    font-size: 64px;
    font-family: "Inter", sans-serif;
    font-weight: 600;
    letter-spacing: 0.02em;
}

.cover-subtitle {
    font-size: 20px;
    font-weight: 500;
    color: #6b7280;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.cover-date {
    font-size: 18px;
    color: #9ca3af;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}
</style>