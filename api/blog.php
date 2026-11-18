<?php
// Обработчик блога Fohow на хостинге.
// GET  ?action=spisok            - записи и стоп-слова для страницы napisat.html (заодно проверка, что обработчик живой)
// POST action=opublikovat        - parol, zag, tekst, foto (файл, необязательно)
// POST action=udalit             - parol, id
// Ответ всегда JSON: {"ok":true,...} или {"ok":false,"oshibka":"текст для человека"}

require __DIR__ . '/blog-lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function otvet(int $kod, array $dannye): void
{
    http_response_code($kod);
    echo json_encode($dannye, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function oshibka(int $kod, string $tekst, array $eshche = []): void
{
    otvet($kod, ['ok' => false, 'oshibka' => $tekst] + $eshche);
}

// --- защита от подбора пароля: после N ошибок с одного адреса пауза ---
function popytki_klyuch(): string
{
    return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|fohow-blog');
}

function proverit_parol(string $parol): void
{
    $n = blog_nastroyki();
    if ($n['parol_hash'] === '') oshibka(503, 'Пароль блога ещё не настроен');
    $fayl = blog_papka() . '/popytki.json';
    $h = blog_zamok();
    $vse = blog_chitat_json($fayl) ?: [];
    $k = popytki_klyuch();
    $seychas = time();
    foreach ($vse as $kk => $v) {
        if (($v['do'] ?? 0) < $seychas && ($v['kogda'] ?? 0) < $seychas - 86400) unset($vse[$kk]);
    }
    $moi = $vse[$k] ?? ['raz' => 0, 'do' => 0, 'kogda' => $seychas];
    if ($moi['do'] > $seychas) {
        flock($h, LOCK_UN);
        $min = (int)ceil(($moi['do'] - $seychas) / 60);
        oshibka(429, "Слишком много неверных паролей. Попробуйте через $min мин.");
    }
    if (password_verify($parol, $n['parol_hash'])) {
        unset($vse[$k]);
        file_put_contents($fayl, json_encode($vse));
        flock($h, LOCK_UN);
        return;
    }
    $moi['raz']++;
    $moi['kogda'] = $seychas;
    if ($moi['raz'] >= $n['popytok']) {
        $moi['do'] = $seychas + 60 * $n['pauza_min'];
        $moi['raz'] = 0;
    }
    $vse[$k] = $moi;
    file_put_contents($fayl, json_encode($vse));
    flock($h, LOCK_UN);
    oshibka(403, 'Неверный пароль');
}

// --- фото: проверяем, что это картинка, уменьшаем, пересохраняем (метаданные с телефона не уходят на сайт) ---
function sohranit_foto(array $f, string $id): ?string
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) oshibka(413, 'Фото слишком большое');
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) oshibka(400, 'Фото не загрузилось, попробуйте ещё раз');
    if ($f['size'] > 20 * 1024 * 1024) oshibka(413, 'Фото больше 20 МБ');
    $info = @getimagesize($f['tmp_name']);
    $tipy = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp', IMAGETYPE_GIF => 'imagecreatefromgif'];
    if (!$info || !isset($tipy[$info[2]])) oshibka(415, 'Это не фото или формат не подходит. Подойдут JPG, PNG, WebP');
    if ($info[0] * $info[1] > 50000000) oshibka(413, 'Фото слишком большое по размеру в точках');
    $im = @$tipy[$info[2]]($f['tmp_name']);
    if (!$im) oshibka(415, 'Не получилось открыть фото');

    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $o = @exif_read_data($f['tmp_name'])['Orientation'] ?? 1;
        $ugol = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($ugol) { $r = imagerotate($im, $ugol, 0); if ($r) { imagedestroy($im); $im = $r; } }
    }
    $w = imagesx($im);
    $h = imagesy($im);
    $max = blog_nastroyki()['foto_max'];
    if (max($w, $h) > $max) {
        $k = $max / max($w, $h);
        $nw = (int)round($w * $k);
        $nh = (int)round($h * $k);
        $mal = imagecreatetruecolor($nw, $nh);
        imagealphablending($mal, false);
        imagesavealpha($mal, true);
        imagecopyresampled($mal, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($im);
        $im = $mal;
    }
    $papka = BLOG_SAYT . '/blog-foto';
    if (!is_dir($papka) && !mkdir($papka, 0755, true) && !is_dir($papka)) oshibka(500, 'Не создать папку для фото');
    if (function_exists('imagewebp')) {
        $imya = $id . '.webp';
        $ok = imagewebp($im, $papka . '/' . $imya, 82);
    } else {
        $imya = $id . '.jpg';
        $ok = imagejpeg($im, $papka . '/' . $imya, 85);
    }
    imagedestroy($im);
    if (!$ok) oshibka(500, 'Не удалось сохранить фото');
    return 'blog-foto/' . $imya;
}

try {
    blog_nastroyki();
    $deystvie = $_GET['action'] ?? $_POST['action'] ?? '';

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $deystvie === 'spisok') {
        $z = array_map(function ($p) {
            return ['id' => $p['id'], 'title' => $p['title'] ?? null, 'text' => mb_substr($p['text'] ?? '', 0, 80),
                    'date' => $p['date'] ?? null, 'label' => $p['label'] ?? null];
        }, blog_poryadok(blog_zapisi()));
        otvet(200, ['ok' => true, 'posts' => $z, 'stop' => blog_stop()]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') oshibka(405, 'Нужен POST');
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) oshibka(413, 'Слишком большой файл для сервера');

    proverit_parol((string)($_POST['parol'] ?? ''));

    if ($deystvie === 'opublikovat') {
        $zag = trim(preg_replace('/\s+/u', ' ', (string)($_POST['zag'] ?? '')));
        $tekst = trim(str_replace("\r", '', (string)($_POST['tekst'] ?? '')));
        if ($tekst === '') oshibka(422, 'Напишите текст записи');
        if (mb_strlen($zag) > 120) oshibka(422, 'Заголовок длиннее 120 знаков');
        if (mb_strlen($tekst) > 5000) oshibka(422, 'Текст длиннее 5000 знаков');
        $stop = blog_stop();
        $krasnye = blog_nayti($zag . "\n" . $tekst, $stop['red']);
        if ($krasnye) oshibka(422, 'В тексте есть слова про лечение, перепишите эти места', ['slova' => $krasnye]);

        $id = date('Y-m-d-His') . '-' . bin2hex(random_bytes(2));
        $img = sohranit_foto($_FILES['foto'] ?? [], $id);
        $zapis = ['id' => $id, 'date' => date('c'), 'label' => null, 'title' => $zag !== '' ? $zag : null,
                  'text' => $tekst, 'img' => $img, 'alt' => $img ? ($zag !== '' ? $zag : 'Фото к записи') : null, 'credit' => null];
        $h = blog_zamok();
        $z = blog_zapisi();
        array_unshift($z, $zapis);
        blog_sohranit($z);
        flock($h, LOCK_UN);
        otvet(200, ['ok' => true, 'id' => $id, 'ssylka' => 'blog.html#' . $id]);
    }

    if ($deystvie === 'udalit') {
        $id = (string)($_POST['id'] ?? '');
        $h = blog_zamok();
        $z = blog_zapisi();
        $ostatok = [];
        $udalena = null;
        foreach ($z as $p) {
            if ($p['id'] === $id && $udalena === null) $udalena = $p; else $ostatok[] = $p;
        }
        if ($udalena === null) { flock($h, LOCK_UN); oshibka(404, 'Запись не найдена, обновите страницу'); }
        blog_sohranit($ostatok);
        flock($h, LOCK_UN);
        // фото удаляем только загруженные через блог, стартовые картинки сайта не трогаем
        if (!empty($udalena['img']) && preg_match('#^blog-foto/[\w.-]+$#', $udalena['img'])) {
            @unlink(BLOG_SAYT . '/' . $udalena['img']);
        }
        otvet(200, ['ok' => true]);
    }

    oshibka(400, 'Неизвестное действие');
} catch (Throwable $e) {
    error_log('blog.php: ' . $e->getMessage());
    oshibka(500, 'Ошибка на сервере. Запись не сохранилась, попробуйте позже');
}
