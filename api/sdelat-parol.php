<?php
// Задать или сменить пароль блога. Только из командной строки:
//   php api/sdelat-parol.php "новый пароль"
// Пишет api/nastroyki.php (прочие настройки в нём сохраняются). Старый пароль после этого не работает
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$parol = $argv[1] ?? '';
if (mb_strlen($parol) < 10) { fwrite(STDERR, "Пароль от 10 знаков\n"); exit(1); }
$fayl = __DIR__ . '/nastroyki.php';
$n = is_file($fayl) ? (require $fayl) : [];
$n['parol_hash'] = password_hash($parol, PASSWORD_DEFAULT);
$n += ['chasovoy_poyas' => 'Europe/Moscow'];
file_put_contents($fayl, "<?php\n// Сделано sdelat-parol.php " . date('Y-m-d H:i') . "\nreturn " . var_export($n, true) . ";\n");
echo "Пароль записан в $fayl\n";
