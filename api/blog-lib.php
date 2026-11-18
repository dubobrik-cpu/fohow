<?php
// Общая часть блога Fohow: данные, проверка слов, разметка записей.
// Разметка повторяет сайт/blog-build.py и textHtml в napisat.html - менять все три вместе.
// Совместимо с PHP 7.4+

if (!defined('BLOG_SAYT')) {
    define('BLOG_SAYT', dirname(__DIR__));
}

function blog_nastroyki(): array
{
    static $n = null;
    if ($n !== null) return $n;
    $fayl = __DIR__ . '/nastroyki.php';
    $n = is_file($fayl) ? (require $fayl) : [];
    $n += [
        'parol_hash' => '',
        // записи храним НАД папкой сайта: выкладка с GitHub их не трогает, из интернета их не открыть
        'papka_dannyh' => dirname(BLOG_SAYT) . '/blog-data',
        'chasovoy_poyas' => 'Europe/Moscow',
        'foto_max' => 1600,
        'popytok' => 5,
        'pauza_min' => 15,
    ];
    if (getenv('BLOG_DATA')) $n['papka_dannyh'] = getenv('BLOG_DATA');
    date_default_timezone_set($n['chasovoy_poyas']);
    return $n;
}

function blog_papka(): string
{
    $p = blog_nastroyki()['papka_dannyh'];
    if (!is_dir($p) && !mkdir($p, 0750, true) && !is_dir($p)) {
        throw new RuntimeException('Не создать папку данных: ' . $p);
    }
    return $p;
}

function blog_chitat_json(string $fayl)
{
    $s = @file_get_contents($fayl);
    if ($s === false) return null;
    if (strncmp($s, "\xEF\xBB\xBF", 3) === 0) $s = substr($s, 3);
    return json_decode($s, true);
}

// записи: из папки данных; при первом запуске берём стартовые из blog/posts.json сайта
function blog_zapisi(): array
{
    $fayl = blog_papka() . '/posts.json';
    $z = blog_chitat_json($fayl);
    if (!is_array($z)) {
        $z = blog_chitat_json(BLOG_SAYT . '/blog/posts.json');
        if (!is_array($z)) $z = [];
    }
    return $z;
}

// запись с копией прежнего файла; вызывать под замком blog_zamok()
function blog_sohranit(array $z): void
{
    $papka = blog_papka();
    $fayl = $papka . '/posts.json';
    $kopii = $papka . '/kopii';
    if (!is_dir($kopii)) @mkdir($kopii, 0750, true);
    if (is_file($fayl)) {
        @copy($fayl, $kopii . '/posts-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.json');
        $vse = glob($kopii . '/posts-*.json') ?: [];
        sort($vse);
        foreach (array_slice($vse, 0, max(0, count($vse) - 60)) as $star) @unlink($star);
    }
    $json = json_encode(array_values($z), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $tmp = $fayl . '.tmp';
    if (file_put_contents($tmp, $json) === false || !rename($tmp, $fayl)) {
        throw new RuntimeException('Не удалось сохранить записи');
    }
}

function blog_zamok()
{
    $h = fopen(blog_papka() . '/.zamok', 'c');
    flock($h, LOCK_EX);
    return $h;
}

function blog_stop(): array
{
    $s = blog_chitat_json(BLOG_SAYT . '/blog/stop-slova.json');
    return ['red' => $s['red'] ?? [], 'yellow' => $s['yellow'] ?? []];
}

// поиск слов из списка: начало слова, «=» в начале значит целое слово (как nayti в napisat.html)
function blog_nayti(string $tekst, array $spisok): array
{
    $low = str_replace('ё', 'е', mb_strtolower($tekst, 'UTF-8'));
    $nashli = [];
    foreach ($spisok as $w) {
        $celoe = mb_substr($w, 0, 1) === '=';
        $koren = str_replace('ё', 'е', mb_strtolower($celoe ? mb_substr($w, 1) : $w, 'UTF-8'));
        $re = '/(?<![\p{L}\p{N}])(' . preg_quote($koren, '/') . ($celoe ? ')(?![\p{L}\p{N}])' : '[\p{L}\p{N}]*)') . '/u';
        if (preg_match_all($re, $low, $m)) {
            foreach ($m[1] as $slovo) {
                if (!in_array($slovo, $nashli, true)) $nashli[] = $slovo;
            }
        }
    }
    return $nashli;
}

function blog_esc($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function blog_data_ru(string $iso): string
{
    $mes = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля',
            'августа', 'сентября', 'октября', 'ноября', 'декабря'];
    $d = new DateTime(substr($iso, 0, 10));
    return (int)$d->format('j') . ' ' . $mes[(int)$d->format('n') - 1] . ' ' . $d->format('Y');
}

function blog_tekst_html(string $t): string
{
    $out = '';
    foreach (preg_split('/\n\s*\n/u', str_replace("\r", '', $t)) as $blok) {
        $para = [];
        $items = [];
        foreach (explode("\n", $blok) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (preg_match('/^[-•]\s+/u', $line)) {
                if ($para) { $out .= '<p>' . implode('<br>', $para) . '</p>'; $para = []; }
                $items[] = '<li>' . blog_esc(preg_replace('/^[-•]\s+/u', '', $line)) . '</li>';
            } else {
                if ($items) { $out .= '<ul>' . implode('', $items) . '</ul>'; $items = []; }
                $para[] = blog_esc($line);
            }
        }
        if ($para) $out .= '<p>' . implode('<br>', $para) . '</p>';
        if ($items) $out .= '<ul>' . implode('', $items) . '</ul>';
    }
    return $out;
}

function blog_zapis_html(array $p): string
{
    $meta = !empty($p['date']) ? blog_data_ru($p['date']) : ($p['label'] ?? '');
    $s = '      <article class="post rise" id="' . blog_esc($p['id']) . "\">\n"
       . '        <div class="post-meta eyebrow">' . blog_esc($meta) . "</div>\n";
    if (!empty($p['title'])) $s .= '        <h2>' . blog_esc($p['title']) . "</h2>\n";
    $s .= '        <div class="post-text">' . blog_tekst_html($p['text'] ?? '') . "</div>\n";
    if (!empty($p['img'])) {
        $wh = '';
        $razmer = @getimagesize(BLOG_SAYT . '/' . $p['img']);
        if ($razmer) $wh = ' width="' . $razmer[0] . '" height="' . $razmer[1] . '"';
        $cap = !empty($p['credit']) ? '<figcaption>' . blog_esc($p['credit']) . '</figcaption>' : '';
        $alt = $p['alt'] ?? ($p['title'] ?? '');
        $s .= '        <figure class="post-pic"><img src="' . blog_esc($p['img']) . '" alt="' . blog_esc($alt)
            . '" loading="lazy"' . $wh . '>' . $cap . "</figure>\n";
    }
    return $s . '      </article>';
}

// новые сверху, стартовые без даты в конце в исходном порядке
function blog_poryadok(array $z): array
{
    $s = array_values(array_filter($z, function ($p) { return !empty($p['date']); }));
    usort($s, function ($a, $b) { return strcmp($b['date'], $a['date']); });
    return array_merge($s, array_values(array_filter($z, function ($p) { return empty($p['date']); })));
}

function blog_stranica(): string
{
    $shablon = file_get_contents(BLOG_SAYT . '/blog.html');
    $lenta = implode("\n", array_map('blog_zapis_html', blog_poryadok(blog_zapisi())));
    $html = preg_replace_callback('/<!--POSTS-->.*?<!--\/POSTS-->/s', function () use ($lenta) {
        return "<!--POSTS-->\n" . $lenta . "\n<!--/POSTS-->";
    }, $shablon, 1);
    return $html === null ? $shablon : $html;
}
