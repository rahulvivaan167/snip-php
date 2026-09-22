<?php

declare(strict_types=1);

namespace App;

/**
 * Turns whatever the user pasted into a URL we are willing to redirect to.
 *
 * A shortener is a redirect service other people will click, so the rules
 * here are deliberately strict: no schemes other than http(s), no credentials
 * embedded in the host, no pointing at the shortener itself, and by default
 * no addresses on the private network behind the server.
 */
final class UrlValidator
{
    public const MAX_LENGTH = 2048;

    public function __construct(
        private readonly ?string $ownHost = null,
        private readonly bool $blockPrivateHosts = true,
    ) {
    }

    /**
     * @throws ValidationException
     */
    public function normalise(string $input): string
    {
        // Control characters would let someone smuggle a second header into
        // the redirect response, so they go before anything else.
        $url = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $input) ?? '');

        if ($url === '') {
            throw new ValidationException('Enter a link to shorten.');
        }

        if (!preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $url)) {
            $url = 'https://' . $url;
        }

        if (strlen($url) > self::MAX_LENGTH) {
            throw new ValidationException('That link is longer than ' . self::MAX_LENGTH . ' characters.');
        }

        $parts = parse_url($url);

        if ($parts === false || !isset($parts['host']) || $parts['host'] === '') {
            throw new ValidationException('That does not look like a valid web address.');
        }

        $scheme = strtolower($parts['scheme'] ?? '');

        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new ValidationException('Only http and https links can be shortened.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ValidationException('Links with a username or password in them are not accepted.');
        }

        $host = strtolower($parts['host']);

        if ($this->ownHost !== null && $host === strtolower($this->ownHost)) {
            throw new ValidationException('That link already points at this shortener.');
        }

        if ($this->blockPrivateHosts && $this->resolvesToPrivateAddress($host)) {
            throw new ValidationException('That host is not publicly reachable, so it cannot be shortened.');
        }

        return $url;
    }

    /**
     * Resolves the host and rejects loopback, link-local, private and
     * reserved ranges. A host that will not resolve at all is treated as
     * unsafe rather than given the benefit of the doubt.
     *
     * Note: gethostbynamel only returns A records, so an IPv6-only host is
     * judged on its literal form alone.
     */
    private function resolvesToPrivateAddress(string $host): bool
    {
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, $flags) === false;
        }

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $literal = substr($host, 1, -1);

            return filter_var($literal, FILTER_VALIDATE_IP, $flags) === false;
        }

        $addresses = gethostbynamel($host);

        if ($addresses === false || $addresses === []) {
            return true;
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, $flags) === false) {
                return true;
            }
        }

        return false;
    }
}
