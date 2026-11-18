<?php
// Отдаёт blog.html со свежими записями. На хостинге .htaccess в корне сайта направляет сюда адрес blog.html,
// поэтому поисковик и читатель видят записи в самой странице, а файл blog.html в репозитории остаётся шаблоном.
require __DIR__ . '/blog-lib.php';
blog_nastroyki();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
echo blog_stranica();
