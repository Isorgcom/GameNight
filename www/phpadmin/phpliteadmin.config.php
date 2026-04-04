<?php
// Disable phpLiteAdmin's own login — GameNight admin auth gate handles access control
$password = '';

// Point directly at the app database
$directory = false;
$databases = [
    ['path' => '/var/db/app.db', 'name' => 'Game Night DB'],
];
