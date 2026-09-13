<?php

/**
 * @var string $baseUrl
 * @var string $csrf
 * @var array<string, mixed>|null $result
 * @var string|null $error
 * @var string $submitted
 */

$shortHost = preg_replace('#^https?://#', '', $baseUrl);
?>
<section class="hero">
    <h1>Paste a long link.<br>Get a short one back.</h1>
    <p class="hero__lede">
        Links never expire unless you say so, and every one of them keeps a
        click log you can read at any time.
    </p>
</section>

<?php if ($error !== null): ?>
    <p class="notice notice--error" role="alert"><?= e($error) ?></p>
<?php endif; ?>

<form class="shorten" method="post" action="<?= e($baseUrl) ?>/shorten">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

    <label class="field">
        <span class="field__label">Link to shorten</span>
        <input
            class="field__input field__input--url"
            type="text"
            name="url"
            inputmode="url"
            autocomplete="url"
            spellcheck="false"
            placeholder="example.com/a/very/long/path"
            value="<?= e($submitted) ?>"
            required
            autofocus>
    </label>

    <details class="options"<?= $submitted !== '' ? ' open' : '' ?>>
        <summary>Custom name and expiry</summary>

        <label class="field">
            <span class="field__label">Custom name</span>
            <span class="prefixed">
                <span class="prefixed__prefix"><?= e($shortHost) ?>/</span>
                <input
                    class="field__input"
                    type="text"
                    name="alias"
                    pattern="[A-Za-z0-9_-]{3,32}"
                    spellcheck="false"
                    placeholder="spring-sale">
            </span>
            <span class="field__hint">3 to 32 letters, numbers, hyphens or underscores. Leave empty for a random one.</span>
        </label>

        <label class="field">
            <span class="field__label">Keep it for</span>
            <select class="field__input" name="expires_in_days">
                <option value="0">Forever</option>
                <option value="1">1 day</option>
                <option value="7">7 days</option>
                <option value="30">30 days</option>
                <option value="365">1 year</option>
            </select>
        </label>
    </details>

    <button class="button" type="submit">Shorten link</button>
</form>

<?php if (is_array($result)): ?>
    <section class="stub" aria-live="polite">
        <p class="stub__label">Your short link</p>

        <p class="stub__link">
            <a href="<?= e((string) $result['short_url']) ?>"><?= e($shortHost) ?>/<strong><?= e((string) $result['code']) ?></strong></a>
        </p>

        <div class="stub__actions">
            <button class="button button--quiet" type="button" data-copy="<?= e((string) $result['short_url']) ?>">Copy</button>
            <a class="button button--quiet" href="<?= e((string) $result['stats_url']) ?>">View clicks</a>
        </div>

        <dl class="stub__meta">
            <dt>Goes to</dt>
            <dd class="truncate"><?= e((string) $result['target_url']) ?></dd>
            <dt>Expires</dt>
            <dd><?= $result['expires_at'] !== null ? e((string) $result['expires_at']) . ' UTC' : 'Never' ?></dd>
        </dl>
    </section>

    <script>
        document.querySelectorAll('[data-copy]').forEach(function (button) {
            button.addEventListener('click', function () {
                var value = button.getAttribute('data-copy');
                var done = function () {
                    button.textContent = 'Copied';
                    setTimeout(function () { button.textContent = 'Copy'; }, 1600);
                };

                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(value).then(done, function () {
                        button.textContent = 'Press Ctrl+C';
                    });
                    return;
                }

                var field = document.createElement('textarea');
                field.value = value;
                document.body.appendChild(field);
                field.select();
                try { document.execCommand('copy'); done(); } catch (error) { button.textContent = 'Press Ctrl+C'; }
                document.body.removeChild(field);
            });
        });
    </script>
<?php endif; ?>
