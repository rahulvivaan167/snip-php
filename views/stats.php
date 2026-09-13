<?php

use App\Link;

/**
 * @var Link $link
 * @var string $shortUrl
 * @var string $baseUrl
 * @var array{total: int, unique: int, last_click: ?string} $summary
 * @var array<string, int> $daily
 * @var array<int, array{source: string, hits: int}> $referrers
 */

$shortHost = preg_replace('#^https?://#', '', $baseUrl);
$counts = array_values($daily);
$peak = $counts === [] ? 1 : max(1, max($counts));
?>
<section class="stub stub--stats">
    <p class="stub__label">Clicks on</p>
    <p class="stub__link"><a href="<?= e($shortUrl) ?>"><?= e($shortHost) ?>/<strong><?= e($link->code) ?></strong></a></p>
    <p class="stub__target truncate">Goes to <?= e($link->targetUrl) ?></p>
</section>

<section class="figures">
    <div class="figure">
        <span class="figure__number"><?= number_format($summary['total']) ?></span>
        <span class="figure__label">clicks</span>
    </div>
    <div class="figure">
        <span class="figure__number"><?= number_format($summary['unique']) ?></span>
        <span class="figure__label">unique visitors</span>
    </div>
    <div class="figure">
        <span class="figure__number figure__number--small">
            <?= $summary['last_click'] !== null ? e($summary['last_click']) : 'none yet' ?>
        </span>
        <span class="figure__label">last click (UTC)</span>
    </div>
</section>

<section class="panel">
    <h2>Last 14 days</h2>

    <ol class="spark">
        <?php foreach ($daily as $day => $hits): ?>
            <li class="spark__day" title="<?= e((string) $day) ?>: <?= (int) $hits ?> clicks">
                <span class="spark__count"><?= (int) $hits ?></span>
                <span class="spark__track">
                    <span class="spark__bar" style="height: <?= max(2, (int) round(($hits / $peak) * 100)) ?>%"></span>
                </span>
                <span class="spark__label"><?= e(substr((string) $day, 8, 2)) ?></span>
            </li>
        <?php endforeach; ?>
    </ol>
</section>

<section class="panel">
    <h2>Where clicks came from</h2>

    <?php if ($referrers === []): ?>
        <p class="empty">No clicks recorded yet. Share the link and refresh this page.</p>
    <?php else: ?>
        <table class="table">
            <thead>
            <tr>
                <th scope="col">Source</th>
                <th scope="col">Clicks</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($referrers as $referrer): ?>
                <tr>
                    <td><?= e($referrer['source']) ?></td>
                    <td><?= number_format($referrer['hits']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Details</h2>
    <dl class="stub__meta">
        <dt>Created</dt>
        <dd><?= e($link->createdAt) ?> UTC</dd>
        <dt>Expires</dt>
        <dd><?= $link->expiresAt !== null ? e($link->expiresAt) . ' UTC' : 'Never' ?></dd>
        <dt>Destination host</dt>
        <dd><?= e($link->targetHost()) ?></dd>
    </dl>
</section>

<p class="back"><a href="<?= e($baseUrl) ?>/">Shorten another link</a></p>
