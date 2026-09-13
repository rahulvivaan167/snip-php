<?php

use App\Link;

/**
 * @var Link $link
 * @var string $baseUrl
 */
?>
<section class="hero">
    <h1>This link has expired</h1>
    <p class="hero__lede">
        It was set to stop working on <?= e((string) $link->expiresAt) ?> UTC,
        so it no longer forwards anywhere.
    </p>
</section>

<p class="back"><a href="<?= e($baseUrl) ?>/">Shorten a link</a></p>
