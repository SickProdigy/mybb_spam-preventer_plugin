<?php
/**
 * Spam Preventer
 *
 * Server-side link and phrase restrictions for low-trust MyBB members.
 *
 * Copyright (C) 2026 SickProdigy
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.');
}

$plugins->add_hook('datahandler_post_validate_post', 'spam_preventer_validate');
$plugins->add_hook('datahandler_post_validate_thread', 'spam_preventer_validate');
$plugins->add_hook('pre_output_page', 'spam_preventer_add_quick_reply_asset');

function spam_preventer_info()
{
    return array(
        'name' => 'Spam Preventer',
        'description' => 'Restricts external links, configured spam phrases, and quote-only replies for new and low-trust members.',
        'website' => 'https://www.sickgaming.net',
        'author' => 'SickProdigy',
        'authorsite' => 'https://www.sickgaming.net',
        'version' => '0.2.0',
        'compatibility' => '18*'
    );
}

function spam_preventer_install()
{
    spam_preventer_ensure_settings();
}

function spam_preventer_is_installed()
{
    global $db;
    $query = $db->simple_select('settinggroups', 'gid', "name='spam_preventer'", array('limit' => 1));
    return (bool)$db->fetch_field($query, 'gid');
}

function spam_preventer_activate()
{
    spam_preventer_ensure_settings();
}

function spam_preventer_deactivate()
{
}

function spam_preventer_uninstall()
{
    global $db;
    $query = $db->simple_select('settinggroups', 'gid', "name='spam_preventer'", array('limit' => 1));
    $gid = (int)$db->fetch_field($query, 'gid');

    if ($gid > 0) {
        $db->delete_query('settings', "gid='{$gid}'");
        $db->delete_query('settinggroups', "gid='{$gid}'");
    }

    rebuild_settings();
}

function spam_preventer_ensure_settings()
{
    global $db;
    $query = $db->simple_select('settinggroups', 'gid', "name='spam_preventer'", array('limit' => 1));
    $gid = (int)$db->fetch_field($query, 'gid');

    if ($gid === 0) {
        $gid = (int)$db->insert_query('settinggroups', array(
            'name' => 'spam_preventer',
            'title' => 'Spam Preventer',
            'description' => 'Link and phrase restrictions for new and low-trust members.',
            'disporder' => 1,
            'isdefault' => 0
        ));
    }

    foreach (spam_preventer_settings($gid) as $setting) {
        $name = $db->escape_string($setting['name']);
        $query = $db->simple_select('settings', 'sid', "name='{$name}'", array('limit' => 1));
        $sid = (int)$db->fetch_field($query, 'sid');

        if ($sid === 0) {
            $db->insert_query('settings', $setting);
            continue;
        }

        unset($setting['value']);
        $db->update_query('settings', $setting, "sid='{$sid}'", 1);
    }

    rebuild_settings();
}

function spam_preventer_settings($gid)
{
    return array(
        spam_preventer_setting('enabled', 'Enable Spam Preventer', 'Reject configured links, phrases, and quote-only replies for eligible low-trust members.', 'yesno', '1', 1, $gid),
        spam_preventer_setting('restricted_groups', 'Restricted Usergroup IDs', 'Comma-separated primary or additional usergroup IDs subject to these rules. MyBB Registered is group 2 by default; on SickGaming this group is named New-Members.', 'text', '2', 2, $gid),
        spam_preventer_setting('post_threshold', 'Minimum Posts for Exemption', 'Members remain restricted below this post count. Set to 0 to disable the post-count condition.', 'numeric', '25', 3, $gid),
        spam_preventer_setting('age_days', 'Minimum Account Age for Exemption', 'Members remain restricted until their account reaches this age in days. Set to 0 to disable the account-age condition.', 'numeric', '3', 4, $gid),
        spam_preventer_setting('block_links', 'Block External Links', 'Reject posts and threads containing links that are not on the trusted-domain list.', 'yesno', '1', 5, $gid),
        spam_preventer_setting('trusted_domains', 'Trusted Domains', 'One domain per line. Subdomains are trusted automatically. Use a leading wildcard for an entire suffix, such as *.edu. Do not include a protocol or path.', 'textarea', "sickgaming.net\ngithub.com\n*.edu\n*.gov", 6, $gid),
        spam_preventer_setting('block_phrases', 'Block Spam Phrases', 'Reject subjects or messages containing configured phrases.', 'yesno', '1', 7, $gid),
        spam_preventer_setting('phrases', 'Blocked Phrases', 'Enter one case-insensitive plain-text phrase per line. Blank lines and lines beginning with # are ignored.', 'textarea', '', 8, $gid),
        spam_preventer_setting('block_quote_only', 'Block Quote-Only Replies', 'Require restricted members to add meaningful original text outside complete MyBB quote blocks. Applies to new replies, including Quick Reply, but not edits or new threads.', 'yesno', '1', 9, $gid),
        spam_preventer_setting('exempt_groups', 'Exempt Usergroup IDs', 'Comma-separated primary or additional usergroup IDs that bypass all rules. Administrators and forum moderators are always exempt.', 'text', '', 10, $gid),
        spam_preventer_setting('exempt_users', 'Exempt User IDs', 'Comma-separated user IDs that bypass all rules.', 'text', '', 11, $gid),
        spam_preventer_setting('exempt_forums', 'Exempt Forum IDs', 'Comma-separated forum IDs where the rules do not apply.', 'text', '', 12, $gid)
    );
}

function spam_preventer_setting($name, $title, $description, $optionscode, $value, $disporder, $gid)
{
    return array(
        'name' => 'spam_preventer_' . $name,
        'title' => $title,
        'description' => $description,
        'optionscode' => $optionscode,
        'value' => $value,
        'disporder' => $disporder,
        'gid' => $gid
    );
}

function spam_preventer_validate(&$datahandler)
{
    global $lang, $mybb;

    if (empty($mybb->settings['spam_preventer_enabled'])) {
        return;
    }

    $data = isset($datahandler->data) && is_array($datahandler->data) ? $datahandler->data : array();
    $fid = isset($data['fid']) ? (int)$data['fid'] : 0;

    if (!spam_preventer_should_restrict($mybb->user, $fid)) {
        return;
    }

    $subject = isset($data['subject']) ? (string)$data['subject'] : '';
    $message = isset($data['message']) ? (string)$data['message'] : '';
    $content = $subject . "\n" . $message;
    $lang->load('spam_preventer');
    $requirements = spam_preventer_requirement_text();

    if (!empty($mybb->settings['spam_preventer_block_links'])
        && spam_preventer_contains_untrusted_link($content, spam_preventer_lines($mybb->settings['spam_preventer_trusted_domains']))) {
        $datahandler->set_error('spam_preventer_link', $requirements);
    }

    if (!empty($mybb->settings['spam_preventer_block_phrases'])
        && spam_preventer_contains_blocked_phrase($content, spam_preventer_lines($mybb->settings['spam_preventer_phrases'], true))) {
        $datahandler->set_error('spam_preventer_phrase', $requirements);
    }

    if (!empty($mybb->settings['spam_preventer_block_quote_only'])
        && spam_preventer_is_new_reply($datahandler, $data)
        && !spam_preventer_has_original_reply_content($message)) {
        $datahandler->set_error('spam_preventer_quote_only');
    }
}

function spam_preventer_is_new_reply($datahandler, $data)
{
    $method = isset($datahandler->method) ? strtolower((string)$datahandler->method) : '';
    return $method === 'insert' && !empty($data['tid']);
}

function spam_preventer_has_original_reply_content($message)
{
    $message = spam_preventer_remove_complete_quotes((string)$message);
    $message = preg_replace('~\[[^\]\r\n]*\]~u', '', $message);
    $message = strip_tags(html_entity_decode($message, ENT_QUOTES, 'UTF-8'));
    $message = preg_replace('~[\pZ\pC]+~u', '', $message);

    return $message !== '';
}

function spam_preventer_remove_complete_quotes($message)
{
    if (!preg_match_all('~\[\s*(/?)\s*quote\b[^\]]*\]~iu', $message, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        return $message;
    }

    $depth = 0;
    $start = null;
    $ranges = array();

    foreach ($matches as $match) {
        $is_closing = $match[1][0] === '/';
        $offset = $match[0][1];
        $length = strlen($match[0][0]);

        if (!$is_closing) {
            if ($depth === 0) {
                $start = $offset;
            }
            ++$depth;
        } elseif ($depth > 0) {
            --$depth;
            if ($depth === 0) {
                $ranges[] = array($start, $offset + $length - $start);
                $start = null;
            }
        }
    }

    for ($index = count($ranges) - 1; $index >= 0; --$index) {
        $message = substr_replace($message, '', $ranges[$index][0], $ranges[$index][1]);
    }

    return $message;
}

function spam_preventer_requirement_text()
{
    global $lang, $mybb;

    $post_threshold = max(0, (int)$mybb->settings['spam_preventer_post_threshold']);
    $age_days = max(0, (int)$mybb->settings['spam_preventer_age_days']);
    $post_requirement = '';
    $age_requirement = '';

    if ($post_threshold > 0) {
        $post_requirement = $lang->sprintf($lang->spam_preventer_requirement_posts, $post_threshold);
    }
    if ($age_days > 0) {
        $age_requirement = $lang->sprintf($lang->spam_preventer_requirement_age, $age_days);
    }
    if ($post_requirement !== '' && $age_requirement !== '') {
        return $lang->sprintf($lang->spam_preventer_requirement_both, $post_requirement, $age_requirement);
    }
    if ($post_requirement !== '') {
        return $post_requirement;
    }
    if ($age_requirement !== '') {
        return $age_requirement;
    }

    return $lang->spam_preventer_requirement_group;
}

function spam_preventer_add_quick_reply_asset($contents)
{
    global $mybb;

    if (empty($mybb->settings['spam_preventer_enabled'])
        || !defined('THIS_SCRIPT')
        || THIS_SCRIPT !== 'showthread.php') {
        return $contents;
    }

    $asset_url = rtrim($mybb->asset_url, '/') . '/jscripts/spam-preventer/quick-reply-errors.js?ver=010';
    $script = '<script type="text/javascript" src="' . htmlspecialchars_uni($asset_url) . '"></script>';

    return preg_replace('~</body>~i', $script . '</body>', $contents, 1);
}

function spam_preventer_should_restrict($user, $fid)
{
    global $mybb;

    if (empty($user['uid'])) {
        return false;
    }

    $uid = (int)$user['uid'];
    if (in_array($uid, spam_preventer_id_list($mybb->settings['spam_preventer_exempt_users']), true)) {
        return false;
    }
    if ($fid > 0 && in_array($fid, spam_preventer_id_list($mybb->settings['spam_preventer_exempt_forums']), true)) {
        return false;
    }

    $primary_group = isset($user['usergroup']) ? (int)$user['usergroup'] : 0;
    $user_groups = array_merge(array($primary_group), spam_preventer_id_list(isset($user['additionalgroups']) ? $user['additionalgroups'] : ''));
    $user_groups = array_values(array_unique(array_filter($user_groups)));

    if (array_intersect($user_groups, spam_preventer_id_list($mybb->settings['spam_preventer_exempt_groups']))) {
        return false;
    }

    $restricted_groups = spam_preventer_id_list($mybb->settings['spam_preventer_restricted_groups']);
    if (!empty($restricted_groups) && !array_intersect($user_groups, $restricted_groups)) {
        return false;
    }

    if (!empty($mybb->usergroup['cancp']) || is_super_admin($uid)) {
        return false;
    }
    if ($fid > 0 && is_moderator($fid, '', $uid)) {
        return false;
    }

    $post_threshold = max(0, (int)$mybb->settings['spam_preventer_post_threshold']);
    $age_days = max(0, (int)$mybb->settings['spam_preventer_age_days']);
    $post_count = isset($user['postnum']) ? (int)$user['postnum'] : 0;
    $registration_time = isset($user['regdate']) ? (int)$user['regdate'] : TIME_NOW;
    $below_post_threshold = $post_threshold > 0 && $post_count < $post_threshold;
    $below_age_threshold = $age_days > 0 && $registration_time > TIME_NOW - ($age_days * 86400);

    if ($post_threshold === 0 && $age_days === 0) {
        return true;
    }

    return $below_post_threshold || $below_age_threshold;
}

function spam_preventer_contains_untrusted_link($content, $trusted_domains)
{
    $content = spam_preventer_normalize_link_text($content);
    $pattern = '~(?<![\pL\pN_])(?:(?:https?|ftp)://[^\s<>\[\]"\']+|www\.[^\s<>\[\]"\']+|(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}(?::\d{1,5})?(?:/[^\s<>\[\]"\']*)?)~iu';

    if (!preg_match_all($pattern, $content, $matches)) {
        return false;
    }

    foreach ($matches[0] as $candidate) {
        $host = spam_preventer_link_host($candidate);
        if ($host === '' || !spam_preventer_domain_is_trusted($host, $trusted_domains)) {
            return true;
        }
    }

    return false;
}

function spam_preventer_normalize_link_text($content)
{
    $content = html_entity_decode((string)$content, ENT_QUOTES, 'UTF-8');
    $content = preg_replace('~\bhxxps?://~iu', 'https://', $content);
    $content = preg_replace('~\s*(?:\[dot\]|\(dot\)|\{dot\})\s*~iu', '.', $content);
    return preg_replace('~(?<=\pL)\s+\.\s+(?=\pL)~u', '.', $content);
}

function spam_preventer_link_host($candidate)
{
    $candidate = trim((string)$candidate, " \t\n\r\0\x0B.,;:!?()[]{}<>\"'");
    if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $candidate)) {
        $candidate = 'http://' . $candidate;
    }
    $host = parse_url($candidate, PHP_URL_HOST);
    return is_string($host) ? strtolower(rtrim($host, '.')) : '';
}

function spam_preventer_domain_is_trusted($host, $trusted_domains)
{
    $host = strtolower(rtrim((string)$host, '.'));
    foreach ($trusted_domains as $trusted_domain) {
        $trusted_domain = strtolower(trim((string)$trusted_domain));
        if (substr($trusted_domain, 0, 2) === '*.') {
            $suffix = ltrim(substr($trusted_domain, 1), '.');
            if ($suffix !== '' && substr($host, -strlen('.' . $suffix)) === '.' . $suffix) {
                return true;
            }
            continue;
        }

        $trusted_domain = spam_preventer_link_host($trusted_domain);
        if ($trusted_domain !== '' && ($host === $trusted_domain || substr($host, -strlen('.' . $trusted_domain)) === '.' . $trusted_domain)) {
            return true;
        }
    }
    return false;
}

function spam_preventer_contains_blocked_phrase($content, $phrases)
{
    foreach ($phrases as $phrase) {
        if ($phrase !== '' && spam_preventer_striposition($content, $phrase) !== false) {
            return true;
        }
    }
    return false;
}

function spam_preventer_striposition($haystack, $needle)
{
    return function_exists('mb_stripos') ? mb_stripos($haystack, $needle, 0, 'UTF-8') : stripos($haystack, $needle);
}

function spam_preventer_lines($value, $allow_comments = false)
{
    $lines = preg_split('~\R~u', (string)$value);
    $result = array();
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || ($allow_comments && strpos($line, '#') === 0)) {
            continue;
        }
        $result[] = $line;
    }
    return array_values(array_unique($result));
}

function spam_preventer_id_list($value)
{
    $ids = preg_split('~[\s,]+~', trim((string)$value));
    $result = array();
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $result[$id] = $id;
        }
    }
    return array_values($result);
}
