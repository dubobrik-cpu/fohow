<?php
// Образец. На хостинге: скопировать в nastroyki.php (рядом) и вписать хэш пароля.
// Хэш делает: php api/sdelat-parol.php "пароль"  - он сам запишет nastroyki.php.
// Сам пароль здесь не хранится, только его хэш. nastroyki.php в git не попадает (.gitignore)
return [
    'parol_hash' => '',
    // где лежат записи; по умолчанию папка blog-data рядом с папкой сайта (не внутри неё)
    // 'papka_dannyh' => '/home/x/xxxxx/blog-data',
    'chasovoy_poyas' => 'Europe/Moscow',
];
