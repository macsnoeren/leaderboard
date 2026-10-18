<?php
/**
 * Huisstijl "Ops Room": design tokens, basiscomponenten, iconen en een paar
 * kleine hulpfuncties voor de opmaak.
 *
 * Er is bewust geen gedeeld CSS-bestand: elke pagina zet <?= themeStyles() ?> in de <head> en houdt
 * daarnaast haar eigen pagina-CSS. Licht thema (admin) is de standaard; data-theme="ops" op <html>
 * geeft het donkere thema (leaderboard, beamer, teamdashboard, auth-pagina's). De admin volgt
 * prefers-color-scheme, tenzij data-theme="light" staat.
 *
 * Het thema is de operatiekamer van een detectiveteam, met mate: een subtiele zebrastreep in kopbalken en
 * het actieve deel van de voortgangsbalk, monospace (met tabular-nums) voor data als codes, timers, levels
 * en rangen, signaalkleur (cyaan) voor wat live is, en een voortgangsbalk met één segment per opdracht.
 * Geen neon, glows of scanlines. Alleen de systeemfonts: geen externe fonts, dus geen IP-adressen van
 * leerlingen naar derden. Kleurcontrast is nagerekend voor beide thema's (tekst ≥ 4,5:1, randen ≥ 3:1);
 * podiumkleuren alleen als achtergrond met donkere tekst.
 *
 * Alleen pure functies: tests laden dit bestand via auth.php.
 */

// marked op een vaste versie: een nieuwe major-versie kan de rendering ongemerkt breken
const MARKED_SRC = 'https://cdn.jsdelivr.net/npm/marked@12.0.2/marked.min.js';
const QRCODE_SRC = 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js';
const CONFETTI_SRC = 'https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js';

// Merkkleuren voor plekken waar geen CSS-variabelen werken (mail, theme-color)
const BRAND_COLOR = '#5B4BDB';
const OPS_BG_COLOR = '#0B1020';

function markedScript(): string {
    return '<script src="' . MARKED_SRC . '"></script>';
}

/**
 * Favicon en theme-color; voor elke <head>.
 */
function themeMeta(string $theme = 'light'): string {
    return '<link rel="icon" href="favicon.svg" type="image/svg+xml">'
        . '<meta name="theme-color" content="' . ($theme === 'ops' ? OPS_BG_COLOR : BRAND_COLOR) . '">';
}

/**
 * Het merkteken: een vergrootglas met zebrastrepen (zelfde tekening als htdocs/favicon.svg).
 */
function brandMark(int $size = 28): string {
    return '<svg class="brand-mark" width="' . $size . '" height="' . $size . '" viewBox="0 0 32 32" aria-hidden="true" focusable="false">'
        . '<rect width="32" height="32" rx="8" fill="#121A2E"/>'
        . '<clipPath id="bm-lens"><circle cx="13.5" cy="13.5" r="7.5"/></clipPath>'
        . '<circle cx="13.5" cy="13.5" r="7.5" fill="#F4F6FB"/>'
        . '<g clip-path="url(#bm-lens)" stroke="#121A2E" stroke-width="2.6">'
        . '<path d="M2 14 14 2M5 19 19 5M9 24 24 9M14 27 27 14"/></g>'
        . '<circle cx="13.5" cy="13.5" r="7.5" fill="none" stroke="#8B7CFF" stroke-width="2.8"/>'
        . '<path d="m19.5 19.5 6 6" stroke="#22D3EE" stroke-width="3.6" stroke-linecap="round"/>'
        . '</svg>';
}

// Iconen: paden uit Lucide (https://lucide.dev, ISC-licentie), 24×24, lijnstijl
const ICONS = [
    'dashboard' => '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
    'message' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    'book' => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
    'megaphone' => '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
    'bot' => '<path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/>',
    'sliders' => '<line x1="21" x2="14" y1="4" y2="4"/><line x1="10" x2="3" y1="4" y2="4"/><line x1="21" x2="12" y1="12" y2="12"/><line x1="8" x2="3" y1="12" y2="12"/><line x1="21" x2="16" y1="20" y2="20"/><line x1="12" x2="3" y1="20" y2="20"/><line x1="14" x2="14" y1="2" y2="6"/><line x1="8" x2="8" y1="10" y2="14"/><line x1="16" x2="16" y1="18" y2="22"/>',
    'gamepad' => '<line x1="6" x2="10" y1="11" y2="11"/><line x1="8" x2="8" y1="9" y2="13"/><line x1="15" x2="15.01" y1="12" y2="12"/><line x1="18" x2="18.01" y1="10" y2="10"/><path d="M17.32 5H6.68a4 4 0 0 0-3.978 3.59C2.604 9.416 2 14.456 2 16a3 3 0 0 0 3 3c1 0 1.5-.5 2-1l1.414-1.414A2 2 0 0 1 9.828 16h4.344a2 2 0 0 1 1.414.586L17 18c.5.5 1 1 2 1a3 3 0 0 0 3-3c0-1.545-.604-6.584-.685-7.258A4 4 0 0 0 17.32 5z"/>',
    'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'user' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/>',
    'key' => '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/>',
    'archive' => '<rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>',
    'clipboard' => '<rect width="8" height="4" x="8" y="2" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/>',
    'external' => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
    'terminal' => '<polyline points="4 17 10 11 4 5"/><line x1="12" x2="20" y1="19" y2="19"/>',
    'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
    'login' => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" x2="3" y1="12" y2="12"/>',
    'arrow-up' => '<path d="m5 12 7-7 7 7"/><path d="M12 19V5"/>',
    'arrow-down' => '<path d="M12 5v14"/><path d="m19 12-7 7-7-7"/>',
    'arrow-left' => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
    'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
    'more' => '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
    'copy' => '<rect width="14" height="14" x="8" y="8" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
    'mail' => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
    'trash' => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/>',
    'plus' => '<path d="M5 12h14"/><path d="M12 5v14"/>',
    'pencil' => '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/>',
    'eye' => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
    'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>',
    'file' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
    'paperclip' => '<path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/>',
    'check' => '<path d="M20 6 9 17l-5-5"/>',
    'x' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
    'alert' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
    'alert-circle' => '<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>',
    'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
    'check-circle' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/>',
    'lock' => '<rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    'unlock' => '<rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/>',
    'play' => '<polygon points="6 3 20 12 6 21 6 3"/>',
    'pause' => '<rect x="14" y="4" width="4" height="16" rx="1"/><rect x="6" y="4" width="4" height="16" rx="1"/>',
    'flag' => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" x2="4" y1="22" y2="15"/>',
    'refresh' => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
    'rotate-ccw' => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
    'monitor' => '<rect width="20" height="14" x="2" y="3" rx="2"/><line x1="8" x2="16" y1="21" y2="21"/><line x1="12" x2="12" y1="17" y2="21"/>',
    'trophy' => '<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>',
    'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    'menu' => '<line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/>',
    'send' => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
    'clock' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    'hand' => '<path d="M18 11V6a2 2 0 0 0-2-2a2 2 0 0 0-2 2"/><path d="M14 10V4a2 2 0 0 0-2-2a2 2 0 0 0-2 2v2"/><path d="M10 10.5V6a2 2 0 0 0-2-2a2 2 0 0 0-2 2v8"/><path d="M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2c-2.8 0-4.5-.86-5.99-2.34l-3.6-3.6a2 2 0 0 1 2.83-2.82L7 15"/>',
    'qr' => '<rect width="5" height="5" x="3" y="3" rx="1"/><rect width="5" height="5" x="16" y="3" rx="1"/><rect width="5" height="5" x="3" y="16" rx="1"/><path d="M21 16h-3a2 2 0 0 0-2 2v3"/><path d="M21 21v.01"/><path d="M12 7v3a2 2 0 0 1-2 2H7"/><path d="M3 12h.01"/><path d="M12 3h.01"/><path d="M12 16v.01"/><path d="M16 12h1"/><path d="M21 12v.01"/><path d="M12 21v-1"/>',
    'phone' => '<rect width="14" height="20" x="5" y="2" rx="2"/><path d="M12 18h.01"/>',
    'save' => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>',
    'shield' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
    'shield-check' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
    'ban' => '<circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/>',
    'thumbs-up' => '<path d="M7 10v12"/><path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"/>',
    'thumbs-down' => '<path d="M17 14V2"/><path d="M9 18.12 10 14H4.17a2 2 0 0 1-1.92-2.56l2.33-8A2 2 0 0 1 6.5 2H20a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-2.76a2 2 0 0 0-1.79 1.11L12 22a3.13 3.13 0 0 1-3-3.88Z"/>',
    'eraser' => '<path d="m7 21-4.3-4.3c-1-1-1-2.5 0-3.4l9.6-9.6c1-1 2.5-1 3.4 0l5.6 5.6c1 1 1 2.5 0 3.4L13 21"/><path d="M22 21H7"/><path d="m5 11 9 9"/>',
    'trending-up' => '<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>',
    'link' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
    'inbox' => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
];

/**
 * Inline SVG-icoon. Decoratief (aria-hidden): zet de betekenis in de tekst, een title of aria-label.
 */
function icon(string $name, string $class = ''): string {
    $paths = ICONS[$name] ?? ICONS['info'];
    return '<svg class="icon' . ($class !== '' ? ' ' . htmlspecialchars($class) : '') . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
}

// ---------------------------------------------------------------------------
// Kleine componenten in PHP
// ---------------------------------------------------------------------------

/**
 * Paginakop: titel, subtitel (bijv. spelnaam + status-badge) en acties rechts. $subtitle en
 * $actions zijn HTML.
 */
function pageHeader(string $title, string $subtitle = '', string $actions = ''): string {
    return '<header class="page-header"><div><h1>' . htmlspecialchars($title) . '</h1>'
        . ($subtitle !== '' ? '<div class="page-subtitle">' . $subtitle . '</div>' : '')
        . '</div>' . ($actions !== '' ? '<div class="page-actions">' . $actions . '</div>' : '') . '</header>';
}

/**
 * Lege toestand: icoon, zin en eventueel een actie (HTML).
 */
function emptyState(string $iconName, string $text, string $action = ''): string {
    return '<div class="empty">' . icon($iconName) . '<p>' . htmlspecialchars($text) . '</p>' . $action . '</div>';
}

/**
 * Gesegmenteerde voortgang: één segment per opdracht. Level L (1..N) = L−1 rond en L actief,
 * N+1 = alles rond (groen). Gelijk aan progressPercent(), maar dan in blokjes.
 */
function progressBar(int $level, int $total, string $class = ''): string {
    $done = max(0, min($total, $level - 1));
    $finished = $total > 0 && $level > $total;
    $label = $finished ? 'Alle ' . $total . ' opdrachten afgerond' : "$done van $total opdrachten afgerond";
    $html = '<div class="progress' . ($finished ? ' is-finished' : '') . ($class !== '' ? ' ' . $class : '') . '" role="progressbar" aria-valuemin="0" aria-valuemax="' . $total . '" aria-valuenow="' . $done . '" aria-label="' . $label . '">';
    for ($i = 1; $i <= max(1, $total); $i++) {
        $state = $i <= $done ? ' done' : ($i === $level && $total > 0 ? ' active' : '');
        $html .= '<span class="seg' . $state . '"></span>';
    }
    return $html . '</div>';
}

/**
 * Rangnummer als cirkel; 1/2/3 in podiumkleur.
 */
function rankBadge(int $rank): string {
    return '<span class="rank' . ($rank >= 1 && $rank <= 3 ? ' rank-' . $rank : '') . '" aria-label="Positie ' . $rank . '">' . $rank . '</span>';
}

/**
 * Auth-layout: donker thema, logo erboven, gecentreerde kaart. Geeft de HTML tot en met de
 * opening van de kaart; sluit af met authPageEnd(). Opties: logo (pad), no_referrer (token in URL),
 * wide (bredere kaart), subtitle (tekst onder de titel), icon (icoon boven de titel), css (extra <style>).
 */
function authPageStart(string $title, array $opts = []): string {
    $logo = $opts['logo'] ?? null;
    $html = '<!DOCTYPE html><html lang="nl" data-theme="ops"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . (!empty($opts['no_referrer']) ? '<meta name="referrer" content="no-referrer">' : '')
        . '<title>' . htmlspecialchars($title) . ' - Zebrawave</title>' . themeMeta('ops') . themeStyles()
        . ($opts['css'] ?? '') . '</head><body class="auth-body"><main class="auth' . (!empty($opts['wide']) ? ' is-wide' : '') . '">'
        . '<div class="auth-brand">' . ($logo ? '<img src="' . htmlspecialchars($logo) . '" alt="" class="auth-logo">' : brandMark(40) . '<span>Zebrawave</span>') . '</div>'
        . '<div class="auth-card">'
        . (isset($opts['icon']) ? '<div class="auth-icon">' . icon($opts['icon']) . '</div>' : '')
        . '<h1>' . htmlspecialchars($title) . '</h1>'
        . (isset($opts['subtitle']) ? '<p class="auth-sub">' . htmlspecialchars($opts['subtitle']) . '</p>' : '');
    return $html;
}

/**
 * Sluit de auth-kaart. $links is HTML onder de kaart (bijv. "← Naar inloggen").
 */
function authPageEnd(string $links = ''): string {
    return '</div>' . ($links !== '' ? '<div class="auth-links">' . $links . '</div>' : '') . '</main>' . themeScript() . '</body></html>';
}

// ---------------------------------------------------------------------------
// Stijl en gedeeld script
// ---------------------------------------------------------------------------

/**
 * Tokens, reset en basiscomponenten als één inline <style>-blok.
 */
function themeStyles(): string {
    return <<<'CSS'
<style>
/* ---------- Tokens: licht thema (admin) ---------- */
:root {
  --brand: #5B4BDB; --brand-hover: #4A3BC4; --brand-soft: #EEECFD; --on-brand: #FFFFFF;
  --signal: #0891B2; --signal-soft: #E0F5FA; --signal-text: #0E7490;
  --bg: #F4F6FB; --surface: #FFFFFF; --surface-2: #F8F9FC; --line: #E3E7F0; --line-strong: #848EA5;
  --text: #141A2B; --text-muted: #5F6B85;
  --success: #166534; --success-soft: #E7F6EC; --on-success: #FFFFFF;
  --warning: #B45309; --warning-soft: #FEF3E2;
  --danger: #B91C1C; --danger-soft: #FDECEC;
  --info: #1D4ED8; --info-soft: #E8EEFD;
  --gold: #F5B700; --silver: #B8C2D6; --bronze: #D08A4E; --on-podium: #141A2B;
  --radius-sm: 6px; --radius: 10px; --radius-lg: 16px; --radius-pill: 999px;
  --shadow-1: 0 1px 2px rgba(16,24,40,.06), 0 1px 3px rgba(16,24,40,.08);
  --shadow-2: 0 12px 32px rgba(16,24,40,.18);
  --focus: rgba(91,75,219,.45);
  --s-1: 4px; --s-2: 8px; --s-3: 12px; --s-4: 16px; --s-5: 24px; --s-6: 32px; --s-7: 48px;
  --font-sans: ui-sans-serif, system-ui, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
  --font-mono: ui-monospace, "JetBrains Mono", "Cascadia Code", Consolas, monospace;
  --ease: cubic-bezier(.2, .8, .2, 1); --t-fast: 150ms; --t: 300ms;
  --stripe: repeating-linear-gradient(-45deg, rgba(255,255,255,.35) 0 6px, transparent 6px 12px);
  color-scheme: light;
}
/* ---------- Donker thema: beamer, leaderboard, teamdashboard, auth ---------- */
[data-theme="ops"] {
  --brand: #8B7CFF; --brand-hover: #A396FF; --brand-soft: #231F4A; --on-brand: #0B1020;
  --signal: #22D3EE; --signal-soft: #0C2E3A; --signal-text: #22D3EE;
  --bg: #0B1020; --surface: #121A2E; --surface-2: #1B2540; --line: #26314F; --line-strong: #5A6688;
  --text: #E6EAF2; --text-muted: #9AA4BF;
  --success: #4ADE80; --success-soft: #0F2E1D; --on-success: #0B1020;
  --warning: #FBBF24; --warning-soft: #33260A;
  --danger: #F87171; --danger-soft: #3A1517;
  --info: #93C5FD; --info-soft: #13244A;
  --shadow-1: 0 1px 2px rgba(0,0,0,.4); --shadow-2: 0 16px 40px rgba(0,0,0,.55);
  --focus: rgba(139,124,255,.6);
  --stripe: repeating-linear-gradient(-45deg, rgba(11,16,32,.35) 0 6px, transparent 6px 12px);
  color-scheme: dark;
}
/* Admin in donkere modus van het systeem (zelfde tokens als ops) */
@media (prefers-color-scheme: dark) {
  :root:not([data-theme]) {
    --brand: #8B7CFF; --brand-hover: #A396FF; --brand-soft: #231F4A; --on-brand: #0B1020;
    --signal: #22D3EE; --signal-soft: #0C2E3A; --signal-text: #22D3EE;
    --bg: #0B1020; --surface: #121A2E; --surface-2: #1B2540; --line: #26314F; --line-strong: #5A6688;
    --text: #E6EAF2; --text-muted: #9AA4BF;
    --success: #4ADE80; --success-soft: #0F2E1D; --on-success: #0B1020;
    --warning: #FBBF24; --warning-soft: #33260A;
    --danger: #F87171; --danger-soft: #3A1517;
    --info: #93C5FD; --info-soft: #13244A;
    --shadow-1: 0 1px 2px rgba(0,0,0,.4); --shadow-2: 0 16px 40px rgba(0,0,0,.55);
    --focus: rgba(139,124,255,.6);
    --stripe: repeating-linear-gradient(-45deg, rgba(11,16,32,.35) 0 6px, transparent 6px 12px);
    color-scheme: dark;
  }
}
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { animation-duration: 1ms !important; animation-iteration-count: 1 !important; transition-duration: 1ms !important; scroll-behavior: auto !important; }
}

/* ---------- Reset en basis ---------- */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { -webkit-text-size-adjust: 100%; }
body { font-family: var(--font-sans); font-size: 16px; line-height: 1.5; background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }
img, svg { display: inline-block; vertical-align: middle; }
a { color: var(--brand); text-underline-offset: 2px; }
a:hover { color: var(--brand-hover); }
h1, h2, h3 { font-weight: 700; }
h1 { font-size: 24px; line-height: 1.25; }
h2 { font-size: 20px; line-height: 1.3; }
h3 { font-size: 16px; }
strong { font-weight: 650; }
code, kbd, .mono { font-family: var(--font-mono); }
.num, .mono { font-variant-numeric: tabular-nums; }
.muted { color: var(--text-muted); }
.small { font-size: 12px; }
.text-sm { font-size: 14px; }
.nowrap { white-space: nowrap; }
.text-right { text-align: right; }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
.icon { width: 18px; height: 18px; flex-shrink: 0; }
:focus-visible { outline: 3px solid var(--focus); outline-offset: 2px; border-radius: 4px; }
::selection { background: var(--brand-soft); }
[hidden] { display: none !important; }

/* ---------- Formulieren ---------- */
input, select, textarea { width: 100%; padding: 9px 12px; border: 1px solid var(--line-strong); border-radius: var(--radius-sm); background: var(--surface); color: var(--text); font: inherit; font-size: 14px; transition: border-color var(--t-fast), box-shadow var(--t-fast); }
textarea { resize: vertical; line-height: 1.5; }
input::placeholder, textarea::placeholder { color: var(--text-muted); opacity: .8; }
input:focus, select:focus, textarea:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px var(--focus); }
input[type="checkbox"], input[type="radio"] { width: 16px; height: 16px; padding: 0; accent-color: var(--brand); flex-shrink: 0; }
input[type="file"] { padding: 7px; font-size: 13px; }
input[type="file"]::file-selector-button { font: inherit; border: 1px solid var(--line-strong); background: var(--surface-2); color: var(--text); border-radius: 4px; padding: 4px 10px; margin-right: 10px; cursor: pointer; }
input:disabled, select:disabled, textarea:disabled { opacity: .6; cursor: not-allowed; }
.field { margin-bottom: var(--s-4); }
.field > label, .field-label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 6px; }
.field .hint { font-size: 12px; color: var(--text-muted); margin-top: 4px; }
.field-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: var(--s-4); }
.check { display: flex; align-items: flex-start; gap: 10px; font-size: 14px; cursor: pointer; }
.check input { margin-top: 3px; }
.input-mono { font-family: var(--font-mono); letter-spacing: .04em; }

/* ---------- Knoppen ---------- */
.btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 9px 16px; border: 1px solid transparent; border-radius: var(--radius-sm); font: inherit; font-size: 14px; font-weight: 600; line-height: 1.2; cursor: pointer; text-decoration: none; white-space: nowrap; background: var(--surface); color: var(--text); transition: background var(--t-fast), border-color var(--t-fast), color var(--t-fast), box-shadow var(--t-fast); }
.btn:hover { color: var(--text); }
.btn .icon { width: 16px; height: 16px; }
.btn-primary { background: var(--brand); color: var(--on-brand); }
.btn-primary:hover { background: var(--brand-hover); color: var(--on-brand); }
.btn-secondary, .btn-outline { background: var(--surface); border-color: var(--line-strong); color: var(--text); }
.btn-secondary:hover, .btn-outline:hover { background: var(--surface-2); border-color: var(--text-muted); }
.btn-ghost { background: transparent; color: var(--text); }
.btn-ghost:hover { background: var(--surface-2); }
.btn-success { background: var(--success); color: var(--on-success); }
.btn-success:hover { filter: brightness(1.08); color: var(--on-success); }
.btn-danger { background: var(--danger-soft); color: var(--danger); border-color: transparent; }
.btn-danger:hover { border-color: var(--danger); color: var(--danger); }
.btn-danger-solid { background: var(--danger); color: #FFFFFF; }
[data-theme="ops"] .btn-danger-solid { color: #0B1020; }
.btn-danger-solid:hover { filter: brightness(1.08); color: inherit; }
.btn-icon { padding: 0; width: 36px; height: 36px; }
.btn-sm { padding: 5px 10px; font-size: 13px; }
.btn-sm.btn-icon { width: 30px; height: 30px; padding: 0; }
.btn-lg { padding: 12px 22px; font-size: 16px; }
.btn-block { width: 100%; }
.btn[disabled], .btn.is-disabled { opacity: .45; cursor: not-allowed; pointer-events: none; }
.btn.is-selected, .btn[aria-pressed="true"] { box-shadow: inset 0 0 0 2px var(--brand); }
form.inline { display: inline; margin: 0; }
.btn-row { display: flex; gap: var(--s-2); flex-wrap: wrap; align-items: center; }

/* ---------- Badges ---------- */
.badge { display: inline-flex; align-items: center; gap: 6px; padding: 2px 10px; border-radius: var(--radius-pill); font-size: 12px; font-weight: 600; line-height: 1.6; white-space: nowrap; background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--line); vertical-align: middle; }
.badge .icon { width: 13px; height: 13px; }
.badge .dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
.badge-success, .badge-running, .badge-goed, .badge-approved { background: var(--success-soft); color: var(--success); border-color: transparent; }
.badge-warning, .badge-paused, .badge-voldoende, .badge-draft-rubric { background: var(--warning-soft); color: var(--warning); border-color: transparent; }
.badge-danger, .badge-onvoldoende { background: var(--danger-soft); color: var(--danger); border-color: transparent; }
.badge-info, .badge-finished { background: var(--info-soft); color: var(--info); border-color: transparent; }
.badge-brand { background: var(--brand-soft); color: var(--brand); border-color: transparent; }
.badge-signal { background: var(--signal-soft); color: var(--signal-text); border-color: transparent; }
.badge-running .dot { animation: pulse 1.6s var(--ease) infinite; }
@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: .3; } }
.count { display: inline-flex; align-items: center; justify-content: center; min-width: 20px; height: 20px; padding: 0 6px; border-radius: var(--radius-pill); background: var(--danger); color: #FFFFFF; font-family: var(--font-mono); font-size: 12px; font-weight: 700; font-variant-numeric: tabular-nums; }
.count-warning { background: var(--warning); }
[data-theme="ops"] .count, [data-theme="ops"] .count-warning { color: #0B1020; }

/* ---------- Kaarten en tegels ---------- */
.card { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); box-shadow: var(--shadow-1); padding: var(--s-5); margin-bottom: var(--s-5); min-width: 0; }
.card-title { display: flex; align-items: center; gap: 8px; font-size: 16px; font-weight: 700; margin-bottom: var(--s-4); }
.card-title .icon { color: var(--text-muted); }
.card-header { display: flex; justify-content: space-between; align-items: center; gap: var(--s-3); flex-wrap: wrap; margin-bottom: var(--s-4); }
.card-header .card-title { margin-bottom: 0; }
.card-actions { display: flex; gap: var(--s-2); flex-wrap: wrap; margin-top: var(--s-4); }
.card-danger { border-color: var(--danger); box-shadow: 0 0 0 1px var(--danger) inset; }
.card-danger .card-title, .card-danger .card-title .icon { color: var(--danger); }
.card > :last-child { margin-bottom: 0; }
.stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: var(--s-4); margin-bottom: var(--s-5); }
.stat { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); box-shadow: var(--shadow-1); padding: var(--s-4) var(--s-5); }
.stat-label { font-size: 12px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--text-muted); display: flex; align-items: center; gap: 6px; }
.stat-label .icon { width: 14px; height: 14px; }
.stat-value { font-family: var(--font-mono); font-size: 28px; font-weight: 700; font-variant-numeric: tabular-nums; line-height: 1.2; margin-top: 4px; }
.stat-hint { font-size: 12px; color: var(--text-muted); }
.stat.is-warning { border-color: var(--warning); background: var(--warning-soft); }
.stat.is-warning .stat-value, .stat.is-warning .stat-label { color: var(--warning); }

/* ---------- Tabellen ---------- */
.table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
table { border-collapse: collapse; width: 100%; }
.table { width: 100%; font-size: 14px; }
.table th { position: sticky; top: 0; z-index: 1; background: var(--surface); text-align: left; font-size: 12px; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: var(--text-muted); padding: 10px 12px; border-bottom: 1px solid var(--line); white-space: nowrap; }
.table td { padding: 12px; border-bottom: 1px solid var(--line); vertical-align: middle; }
.table tbody tr:last-child td { border-bottom: none; }
.table tbody tr:hover td { background: var(--surface-2); }
.table-compact th, .table-compact td { padding: 6px 8px; }
.table .col-actions { text-align: right; white-space: nowrap; }
.table .col-num { text-align: right; font-variant-numeric: tabular-nums; }
.table .col-center { text-align: center; }
.table td.is-empty { padding: 0; }

/* ---------- Meldingen ---------- */
.alert { display: flex; gap: 12px; align-items: flex-start; padding: 12px 16px; border: 1px solid; border-left-width: 4px; border-radius: var(--radius-sm); margin-bottom: var(--s-5); font-size: 14px; line-height: 1.5; }
.alert > .icon { margin-top: 1px; }
.alert > div { min-width: 0; flex: 1; }
.alert-success { background: var(--success-soft); border-color: var(--success); color: var(--text); }
.alert-success > .icon { color: var(--success); }
.alert-error { background: var(--danger-soft); border-color: var(--danger); color: var(--text); }
.alert-error > .icon { color: var(--danger); }
.alert-warning { background: var(--warning-soft); border-color: var(--warning); color: var(--text); }
.alert-warning > .icon { color: var(--warning); }
.alert-info { background: var(--info-soft); border-color: var(--info); color: var(--text); }
.alert-info > .icon { color: var(--info); }
.copy-field { display: flex; gap: var(--s-2); margin-top: var(--s-2); }
.copy-field input { font-family: var(--font-mono); font-size: 13px; }

/* ---------- Modal (native <dialog>) ---------- */
dialog.modal { border: none; border-radius: var(--radius-lg); padding: 0; width: min(760px, calc(100vw - 32px)); max-height: calc(100vh - 48px); margin: auto; background: var(--surface); color: var(--text); box-shadow: var(--shadow-2); }
dialog.modal[open] { display: flex; flex-direction: column; animation: modal-in var(--t) var(--ease); }
dialog.modal::backdrop { background: rgba(8, 12, 24, .65); }
@keyframes modal-in { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }
.modal-header { display: flex; justify-content: space-between; align-items: center; gap: var(--s-3); padding: 18px 24px; border-bottom: 1px solid var(--line); }
.modal-header h2 { font-size: 18px; }
.modal-body { padding: 24px; overflow-y: auto; }
.modal-footer { display: flex; justify-content: flex-end; gap: var(--s-2); padding: 14px 24px; border-top: 1px solid var(--line); }
.section-label { font-size: 12px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--text-muted); margin: 20px 0 8px; }
.modal-body > .section-label:first-child, .card > .section-label:first-child { margin-top: 0; }
.panel { background: var(--surface-2); border: 1px solid var(--line); border-radius: var(--radius-sm); padding: 14px 16px; }
.panel-danger { background: var(--danger-soft); border-color: transparent; }

/* ---------- Voortgang (één segment per opdracht) ---------- */
.progress { display: flex; gap: 3px; height: 8px; min-width: 80px; }
.progress .seg { flex: 1; min-width: 2px; background: var(--line); border-radius: 2px; transition: background var(--t); }
.progress .seg.done { background: var(--brand); }
.progress .seg.active { background-color: var(--signal); background-image: var(--stripe); background-size: 17px 17px; animation: stripe-move 1s linear infinite; }
.progress.is-finished .seg { background: var(--success); }
@keyframes stripe-move { to { background-position: 17px 0; } }

/* ---------- Rang ---------- */
.rank { display: inline-grid; place-items: center; width: 32px; height: 32px; flex-shrink: 0; border-radius: 50%; background: var(--surface-2); border: 1px solid var(--line); color: var(--text); font-family: var(--font-mono); font-size: 14px; font-weight: 700; font-variant-numeric: tabular-nums; }
.rank-1 { background: var(--gold); border-color: transparent; color: var(--on-podium); }
.rank-2 { background: var(--silver); border-color: transparent; color: var(--on-podium); }
.rank-3 { background: var(--bronze); border-color: transparent; color: var(--on-podium); }

/* ---------- Chat ---------- */
.chat { display: flex; flex-direction: column; gap: 10px; }
.bubble { max-width: 80%; padding: 10px 14px; border-radius: 14px; border: 1px solid var(--line); background: var(--surface-2); color: var(--text); font-size: 15px; line-height: 1.45; overflow-wrap: anywhere; align-self: flex-start; }
.bubble-meta { display: flex; gap: 6px; align-items: center; font-size: 12px; color: var(--text-muted); margin-bottom: 3px; }
.bubble-meta strong { color: var(--text); font-weight: 600; }
.bubble-meta .icon { width: 14px; height: 14px; }
.bubble-team { background: var(--brand-soft); border-color: transparent; }
.bubble-teacher, .bubble-ai { align-self: flex-end; border-bottom-right-radius: 4px; }
.bubble-team { border-bottom-left-radius: 4px; }
.bubble-ai { border-left: 3px solid var(--signal); }
.bubble-suggestion { background: transparent; border: 1px dashed var(--brand); max-width: 92%; }
.bubble-escalation { background: var(--warning-soft); border: 2px solid var(--warning); }
.bubble-info { background: transparent; border: 1px dashed var(--line-strong); }
.bubble-actions { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px; }
/* Teamkant: jullie rechts, docent links */
.chat-team-view .bubble-team { align-self: flex-end; border-bottom-left-radius: 14px; border-bottom-right-radius: 4px; }
.chat-team-view .bubble-teacher { align-self: flex-start; border-bottom-right-radius: 14px; border-bottom-left-radius: 4px; }

/* ---------- Markdown ---------- */
.markdown { line-height: 1.65; overflow-wrap: anywhere; }
.markdown > :first-child { margin-top: 0; }
.markdown > :last-child { margin-bottom: 0; }
.markdown h1 { font-size: 1.3em; margin: 18px 0 8px; }
.markdown h2 { font-size: 1.18em; margin: 16px 0 8px; }
.markdown h3, .markdown h4 { font-size: 1.05em; margin: 14px 0 6px; }
.markdown p { margin: 0 0 12px; }
.markdown ul, .markdown ol { margin: 0 0 12px 22px; }
.markdown li { margin-bottom: 4px; }
.markdown code { font-family: var(--font-mono); font-size: .88em; background: var(--surface-2); border: 1px solid var(--line); padding: 1px 5px; border-radius: 4px; }
.markdown pre { background: #0B1020; color: #E6EAF2; padding: 14px 16px; border-radius: var(--radius-sm); overflow-x: auto; margin: 0 0 12px; border: 1px solid var(--line); }
.markdown pre code { background: none; border: none; padding: 0; color: inherit; font-size: .85em; }
.markdown blockquote { border-left: 3px solid var(--line-strong); padding-left: 12px; color: var(--text-muted); margin: 0 0 12px; }
.markdown table { border-collapse: collapse; margin: 0 0 12px; width: auto; }
.markdown th, .markdown td { border: 1px solid var(--line); padding: 6px 10px; text-align: left; }
.markdown img { max-width: 100%; height: auto; }
.markdown hr { border: none; border-top: 1px solid var(--line); margin: 16px 0; }
.markdown[data-md]:not([data-rendered]) { white-space: pre-wrap; }

/* ---------- Lege toestand, paginakop, toolbar, menu ---------- */
.empty { text-align: center; padding: 40px 16px; color: var(--text-muted); }
.empty > .icon { width: 40px; height: 40px; color: var(--line-strong); margin-bottom: 12px; stroke-width: 1.5; }
.empty p { margin-bottom: 12px; }
.empty p:last-child { margin-bottom: 0; }
.page-header { display: flex; justify-content: space-between; align-items: flex-start; gap: var(--s-4); flex-wrap: wrap; margin-bottom: var(--s-5); }
.page-header h1 { font-size: 24px; font-weight: 750; letter-spacing: -.01em; }
.page-subtitle { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; color: var(--text-muted); font-size: 14px; margin-top: 4px; }
.page-actions { display: flex; gap: var(--s-2); flex-wrap: wrap; }
.toolbar { display: flex; gap: var(--s-3); align-items: flex-end; flex-wrap: wrap; }
.toolbar .field { margin-bottom: 0; }
.toolbar select, .toolbar input { width: auto; min-width: 170px; }
.menu { position: relative; display: inline-block; }
.menu-list { position: fixed; z-index: 3000; min-width: 220px; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius-sm); box-shadow: var(--shadow-2); padding: 4px; }
.menu-list form { margin: 0; }
.menu-item { display: flex; align-items: center; gap: 10px; width: 100%; padding: 8px 10px; background: none; border: none; border-radius: 4px; font: inherit; font-size: 14px; color: var(--text); text-align: left; text-decoration: none; cursor: pointer; white-space: nowrap; }
.menu-item:hover, .menu-item:focus-visible { background: var(--surface-2); color: var(--text); }
.menu-item .icon { width: 16px; height: 16px; color: var(--text-muted); }
.menu-item.is-danger, .menu-item.is-danger .icon { color: var(--danger); }
.menu-sep { height: 1px; background: var(--line); margin: 4px 0; }
.chip { display: inline-flex; align-items: center; gap: 6px; background: var(--brand-soft); color: var(--text); border-radius: var(--radius-pill); padding: 3px 4px 3px 12px; margin: 0 6px 6px 0; font-size: 13px; }
.kbd { font-family: var(--font-mono); font-size: 11px; padding: 1px 5px; border: 1px solid var(--line-strong); border-radius: 4px; color: var(--text-muted); }
.eyebrow { font-family: var(--font-mono); font-size: 12px; font-weight: 600; letter-spacing: .18em; text-transform: uppercase; color: var(--signal); }
.live-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: var(--signal); box-shadow: 0 0 0 0 var(--signal); animation: live 2s ease-out infinite; }
@keyframes live { 0% { box-shadow: 0 0 0 0 rgba(34,211,238,.6); } 100% { box-shadow: 0 0 0 10px rgba(34,211,238,0); } }
.qr-card { background: #FFFFFF; padding: 10px; border-radius: var(--radius); display: inline-block; line-height: 0; }
.qr-card img, .qr-card canvas { display: block; }
.grid-main { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap: var(--s-5); align-items: start; }
.grid-halves { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: var(--s-5); align-items: start; }
@media (max-width: 1200px) { .grid-main, .grid-halves { grid-template-columns: minmax(0, 1fr); } }

/* ---------- Schermvullende toestanden (wachten, pauze, einde) ---------- */
.screen { min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: var(--s-5); padding: var(--s-6) var(--s-4); background: radial-gradient(ellipse at 50% 0%, var(--surface-2) 0%, var(--bg) 65%); color: var(--text); }
.screen-logo { max-width: min(80vw, 560px); max-height: 40vh; object-fit: contain; filter: drop-shadow(0 12px 30px rgba(0,0,0,.4)); }
.screen-title { font-size: clamp(28px, 5vw, 56px); font-weight: 800; letter-spacing: -.02em; line-height: 1.1; }
.screen-sub { font-size: clamp(16px, 2.4vw, 24px); color: var(--text-muted); max-width: 900px; }
.screen-box { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); padding: 14px 22px; font-size: clamp(15px, 2vw, 20px); max-width: 800px; white-space: pre-line; }
.waiting-dots::after { content: ''; display: inline-block; width: 1.2em; text-align: left; animation: dots 1.5s steps(4, end) infinite; }
@keyframes dots { 0% { content: ''; } 25% { content: '.'; } 50% { content: '..'; } 75% { content: '...'; } }
.zebra-band { background-image: repeating-linear-gradient(-45deg, rgba(139,124,255,.07) 0 10px, transparent 10px 20px); }

/* ---------- Auth-layout ---------- */
.auth-body { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 32px 16px; background: radial-gradient(ellipse at 50% 0%, var(--surface-2) 0%, var(--bg) 60%); }
.auth { width: 100%; max-width: 440px; }
.auth-brand { display: flex; align-items: center; justify-content: center; gap: 12px; margin-bottom: 24px; font-size: 22px; font-weight: 800; letter-spacing: .02em; }
.auth-logo { max-width: 100%; max-height: 140px; object-fit: contain; border-radius: var(--radius); }
.auth-card { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius-lg); box-shadow: var(--shadow-2); padding: 32px; }
.auth.is-wide { max-width: 520px; }
.auth-card h1 { font-size: 22px; margin-bottom: 18px; }
.auth-sub { color: var(--text-muted); margin: -12px 0 20px; font-size: 15px; }
.auth-icon { width: 44px; height: 44px; border-radius: 12px; display: grid; place-items: center; background: var(--brand-soft); color: var(--brand); margin-bottom: 14px; }
.auth-icon .icon { width: 22px; height: 22px; }
.auth-card .btn-block { margin-top: 8px; }
.auth-links { text-align: center; margin-top: 20px; font-size: 14px; display: flex; gap: 16px; justify-content: center; flex-wrap: wrap; }
.auth-links a { color: var(--text-muted); text-decoration: none; }
.auth-links a:hover { color: var(--text); text-decoration: underline; }
.auth-note { font-size: 13px; color: var(--text-muted); margin-top: 16px; line-height: 1.5; }
@media (max-width: 480px) { .auth-card { padding: 24px 20px; } }
</style>
CSS;
}

/**
 * Gedeeld gedrag, met event delegation zodat het ook werkt voor HTML die later binnenkomt:
 * - dialog.modal: klik op de achtergrond of [data-close] sluit; [data-open="id"] opent.
 * - [data-menu]: knop met een menu (.menu-list) erachter; Esc of klik buiten sluit.
 * - [data-copy="tekst"]: kopieert naar het klembord (met een prompt als dat niet kan).
 * - renderMarkdown(root): .markdown[data-md] via marked (als die geladen is).
 */
function themeScript(): string {
    return <<<'JS'
<script>
(function () {
    window.renderMarkdown = function (root) {
        if (!window.marked) return;
        marked.setOptions({ breaks: true });
        (root || document).querySelectorAll('.markdown[data-md]:not([data-rendered])').forEach(function (el) {
            el.innerHTML = marked.parse(el.textContent.trim());
            el.setAttribute('data-rendered', 'true');
        });
    };
    function closeMenus(except) {
        document.querySelectorAll('[data-menu][aria-expanded="true"]').forEach(function (btn) {
            if (btn === except) return;
            btn.setAttribute('aria-expanded', 'false');
            var list = btn.nextElementSibling;
            if (list) list.hidden = true;
        });
    }
    function copyText(text, btn) {
        var done = function () {
            if (!btn) return;
            var old = btn.getAttribute('data-label') || btn.innerHTML;
            btn.setAttribute('data-label', old);
            var label = btn.querySelector('.copy-label');
            if (label) { label.textContent = 'Gekopieerd'; } else if (!btn.classList.contains('btn-icon')) { btn.textContent = 'Gekopieerd'; }
            btn.classList.add('is-selected');
            setTimeout(function () { btn.innerHTML = old; btn.classList.remove('is-selected'); }, 1500);
        };
        // Het klembord werkt alleen via https of localhost
        if (!navigator.clipboard) { prompt('Kopieer de tekst:', text); return; }
        navigator.clipboard.writeText(text).then(done, function () { prompt('Kopieer de tekst:', text); });
    }
    document.addEventListener('click', function (e) {
        var t = e.target;
        if (t instanceof HTMLDialogElement && t.classList.contains('modal')) { t.close(); return; }
        var closer = t.closest('[data-close]');
        if (closer) { var d = closer.closest('dialog'); if (d) d.close(); return; }
        var opener = t.closest('[data-open]');
        if (opener) { var dlg = document.getElementById(opener.getAttribute('data-open')); if (dlg) { dlg.showModal(); window.renderMarkdown(dlg); } return; }
        var copy = t.closest('[data-copy]');
        if (copy) { e.preventDefault(); copyText(copy.getAttribute('data-copy'), copy); closeMenus(); return; }
        var menuBtn = t.closest('[data-menu]');
        if (menuBtn) {
            var list = menuBtn.nextElementSibling;
            var open = menuBtn.getAttribute('aria-expanded') === 'true';
            closeMenus(menuBtn);
            menuBtn.setAttribute('aria-expanded', open ? 'false' : 'true');
            list.hidden = open;
            if (!open) {
                // position: fixed, zodat een tabel met overflow het menu niet afsnijdt
                var r = menuBtn.getBoundingClientRect();
                var w = list.offsetWidth, h = list.offsetHeight;
                var top = r.bottom + 4;
                if (top + h > window.innerHeight - 8) top = Math.max(8, r.top - h - 4);
                list.style.top = top + 'px';
                list.style.left = Math.max(8, Math.min(r.right - w, window.innerWidth - w - 8)) + 'px';
                var first = list.querySelector('.menu-item');
                if (first) first.focus();
            }
            return;
        }
        if (!t.closest('.menu-list')) closeMenus();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            var openBtn = document.querySelector('[data-menu][aria-expanded="true"]');
            if (openBtn) { closeMenus(); openBtn.focus(); }
        }
    });
    window.addEventListener('scroll', function () { closeMenus(); }, true);
    window.addEventListener('resize', function () { closeMenus(); });
    document.addEventListener('DOMContentLoaded', function () { window.renderMarkdown(); });
})();
</script>
JS;
}
