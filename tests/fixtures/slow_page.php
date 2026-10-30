<?php

// A page that takes its time, served by `php -S` in web_fetch_test:
//   /pause   sends half, waits a second, sends the rest
//   /silent  sends nothing for five seconds
if ($_SERVER['REQUEST_URI'] === '/silent') {
    sleep(5);
}
header('Content-Type: text/plain');
echo 'first half, ';
flush();
if ($_SERVER['REQUEST_URI'] === '/pause') {
    sleep(1);
}
echo 'second half';
