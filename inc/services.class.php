<?php
/**
 * Git Plugin Installer — the optional "Nos Produits et Services" menu that a
 * Convergent-hosted install appends to its update digest. OFF by default
 * (config `notify_services_menu`): a public install never sends it.
 *
 * TO REMOVE once the GLPI team accepts gitplugins into the official catalogue
 * (owner, 14 Nov 2026): this file, the `notify_services_menu` column, its
 * getter, its config switch, its locale string and the `$withServices` branch
 * of PluginGitpluginsUpdatecheck::buildDigest().
 *
 * Same title, list, order and icons as GLPI-notification-templates
 * (brand.json), relance, AssetCVE and comm; ServicesTest checks it against
 * brand.json when that file sits next to this repository. Emoji, not images:
 * nothing a mail client can block. U+FE0F keeps ☁, 🛡 and 🛠 in colour in
 * Outlook.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

final class PluginGitpluginsServices
{
    public const TITLE = 'Nos Produits et Services';

    /** @var list<array{icon:string,label:string,url:string}> */
    public const SERVICES = [
        ['icon' => "\u{2601}\u{FE0F}",  'label' => 'Cloud privé',            'url' => 'https://www.convergent.tn/cloud-prive-tunisie.html'],
        ['icon' => "\u{1F9F1}",         'label' => 'Pare-feu',               'url' => 'https://www.convergent.tn/firewall-tunisie.html'],
        ['icon' => "\u{1F4BE}",         'label' => 'Sauvegarde immuable',    'url' => 'https://www.convergent.tn/backup-immuable.html'],
        ['icon' => "\u{1F6E1}\u{FE0F}", 'label' => 'Protection EDR',         'url' => 'https://www.convergent.tn/edr.html'],
        ['icon' => "\u{1F4E7}",         'label' => 'Messagerie Sky',         'url' => 'https://smail.tn/'],
        ['icon' => "\u{1F6E0}\u{FE0F}", 'label' => 'GLPI infogéré',          'url' => 'https://www.convergent.tn/glpi.html'],
        ['icon' => "\u{1F50D}",         'label' => 'Audit de cybersécurité', 'url' => 'https://www.convergent.tn/audit-cyber.html'],
    ];

    /** Menu links: https, Convergent's own domains only. */
    private const URL = '#^https://(www\.convergent\.tn|pharos\.tn|smail\.tn)(/[A-Za-z0-9._~/-]*)?$#';

    /** A menu icon is one emoji: 1 to 8 code points, nothing else. */
    private const ICON = '/^[\x{2190}-\x{2BFF}\x{1F000}-\x{1FAFF}\x{FE0F}\x{200D}]{1,8}\z/u';

    /** True when every entry is safe; one bad entry drops the whole menu (fail closed). */
    public static function isValid(array $services, string $title): bool
    {
        if ($services === [] || trim($title) === '' || preg_match('/[\x00-\x1f]/', $title)) {
            return false;
        }
        foreach ($services as $svc) {
            $icon  = $svc['icon'] ?? '';
            $label = $svc['label'] ?? null;
            $url   = $svc['url'] ?? null;
            if (!is_string($label) || trim($label) === '' || preg_match('/[\x00-\x1f]/', $label)
                || !is_string($url) || !preg_match(self::URL, $url)
                || !is_string($icon) || ($icon !== '' && !preg_match(self::ICON, $icon))) {
                return false;
            }
        }

        return true;
    }

    /** The menu as plain text, one "icon label : url" per line, or ''. */
    public static function text(array $services = self::SERVICES, string $title = self::TITLE): string
    {
        if (!self::isValid($services, $title)) {
            return '';
        }
        $lines = [$title];
        foreach ($services as $svc) {
            $lines[] = trim(($svc['icon'] ?? '') . ' ' . $svc['label']) . ' : ' . $svc['url'];
        }

        return implode("\n", $lines);
    }
}
