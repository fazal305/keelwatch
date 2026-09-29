<?php

declare(strict_types=1);

namespace Keelwatch\Notify;

/**
 * Which webhook URLs may be stored as notification destinations. Mirrors the
 * worker's validate_url(); contracts/test-vectors/notification-destination-urls.v1.json
 * holds the cases both implementations must agree on.
 *
 * This is the SSRF allowlist: exact Slack/Discord hosts and path shapes over
 * https on the default port, with no userinfo, query or fragment. The worker
 * additionally checks that the host resolves only to public addresses right
 * before every send, so a stored URL can't be pointed inward later.
 */
final class DestinationUrl
{
    public const KINDS = ['slack', 'discord'];

    private const SLACK_PATH = '#^/services/[A-Za-z0-9]+/[A-Za-z0-9]+/[A-Za-z0-9]+$#D';
    private const DISCORD_PATH = '#^/api/webhooks/[0-9]{1,25}/[A-Za-z0-9_-]{1,100}$#D';
    private const MAX_LENGTH = 500;

    /**
     * @return string the lower-cased host
     * @throws InvalidDestination
     */
    public static function validate(string $kind, string $url): string
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidDestination('Kind must be one of: ' . implode(', ', self::KINDS) . '.');
        }
        $url = trim($url);
        if ($url === '' || strlen($url) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7F]/', $url)) {
            throw new InvalidDestination('Not a valid URL.');
        }
        // Check the raw authority for '@' before parsing: parsers disagree on
        // "https://evil@hooks.slack.com" style URLs, so refuse them outright.
        if (!preg_match('#^([A-Za-z][A-Za-z0-9+.-]*)://([^/?\#]*)([^?\#]*)(\?[^\#]*)?(\#.*)?$#Ds', $url, $m)) {
            throw new InvalidDestination('Not a valid URL.');
        }
        [$scheme, $authority, $path] = [$m[1], $m[2], $m[3]];
        $query = $m[4] ?? '';
        $fragment = $m[5] ?? '';
        if (strtolower($scheme) !== 'https') {
            throw new InvalidDestination('Webhook URLs must use https.');
        }
        if (str_contains($authority, '@')) {
            throw new InvalidDestination('Webhook URLs must not contain credentials before the host.');
        }
        if (!preg_match('#^([A-Za-z0-9.-]+)(?::([0-9]{1,5}))?$#D', $authority, $a)) {
            throw new InvalidDestination('Not a valid URL.');
        }
        $host = strtolower($a[1]);
        $port = isset($a[2]) && $a[2] !== '' ? (int) $a[2] : null;
        if ($port !== null && $port !== 443) {
            throw new InvalidDestination('Webhook URLs must use the default https port.');
        }
        if ($query !== '' || $fragment !== '') {
            throw new InvalidDestination('Webhook URLs must not have a query string or fragment.');
        }
        if ($kind === 'slack') {
            if ($host !== 'hooks.slack.com' || !preg_match(self::SLACK_PATH, $path)) {
                throw new InvalidDestination('Expected https://hooks.slack.com/services/…');
            }
        } elseif (!in_array($host, ['discord.com', 'discordapp.com'], true) || !preg_match(self::DISCORD_PATH, $path)) {
            throw new InvalidDestination('Expected https://discord.com/api/webhooks/<id>/<token>');
        }
        return $host;
    }
}
