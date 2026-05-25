<?php

$envPath = __DIR__ . '/../.env';
$env = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

if ($env === false) {
  fwrite(STDERR, "Unable to read environment file: {$envPath}\n");
  exit(1);
}

foreach ($env as $line) {
  $line = trim($line);

  if ($line === '' || str_starts_with($line, '#')) {
    continue;
  }

  [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
  $name = trim($name);
  $value = trim($value);

  if ($name !== '' && !defined($name)) {
    define($name, $value);
  }
}

$dbHost = defined('DB_HOST') ? DB_HOST : '';
$dbUsername = defined('DB_USERNAME') ? DB_USERNAME : (defined('DB_USER') ? DB_USER : '');
$dbPassword = defined('DB_PASSWORD') ? DB_PASSWORD : '';
$dbDatabase = defined('DB_DATABASE') ? DB_DATABASE : (defined('DB_NAME') ? DB_NAME : '');
  
$connect = false;

if ($dbHost !== '' && $dbUsername !== '' && $dbDatabase !== '') {
  $connectHost = $dbHost === 'localhost' ? '127.0.0.1' : $dbHost;
  $connect = @mysqli_connect($connectHost, $dbUsername, $dbPassword, $dbDatabase);

  if ($connect === false && $connectHost !== $dbHost) {
    $connect = @mysqli_connect($dbHost, $dbUsername, $dbPassword, $dbDatabase);
  }
}
