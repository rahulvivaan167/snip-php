<?php

/**
 * @var string $title
 * @var string $appName
 * @var string $baseUrl
 * @var string $content
 */

$appName = $appName ?? 'Snip';
$baseUrl = $baseUrl ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e($title ?? $appName) ?></title>
    <link rel="stylesheet" href="<?= e($baseUrl) ?>/assets/style.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'><rect width='16' height='16' rx='3' fill='%2310243A'/><path d='M4 8h8' stroke='%23F2B705' stroke-width='2' stroke-linecap='round'/></svg>">
</head>
<body>
<header class="masthead">
    <a class="wordmark" href="<?= e($baseUrl) ?>/"><?= e($appName) ?></a>
    <span class="masthead__note">self-hosted link shortener</span>
</header>

<main class="page">
    <?= $content ?>
</main>

<footer class="footer">
    <p>Runs on PHP and one SQLite file. No tracking cookies, no third-party scripts.</p>
</footer>
</body>
</html>
