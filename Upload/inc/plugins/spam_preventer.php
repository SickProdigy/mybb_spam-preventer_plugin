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
        'version' => '1.0.1',
        'compatibility' => '18*'
    );
}

function spam_preventer_install()
{
    spam_preventer_ensure_settings();
    spam_preventer_ensure_tables();
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
    spam_preventer_ensure_tables();
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

    if ($db->table_exists('spam_preventer_logs')) {
        $db->drop_table('spam_preventer_logs');
    }
    if ($db->table_exists('spam_preventer_cooldowns')) {
        $db->drop_table('spam_preventer_cooldowns');
    }

    rebuild_settings();
}

function spam_preventer_ensure_tables()
{
    global $db;

    $collation = $db->build_create_table_collation();
    if (!$db->table_exists('spam_preventer_logs')) {
        $db->write_query("CREATE TABLE " . TABLE_PREFIX . "spam_preventer_logs (
            lid int unsigned NOT NULL auto_increment,
            uid int unsigned NOT NULL default 0,
            username varchar(120) NOT NULL default '',
            fid int unsigned NOT NULL default 0,
            rule varchar(40) NOT NULL default '',
            action varchar(20) NOT NULL default '',
            subject varchar(120) NOT NULL default '',
            excerpt text NULL,
            dateline int unsigned NOT NULL default 0,
            ipaddress varbinary(16) NOT NULL default '',
            PRIMARY KEY (lid),
            KEY uid (uid),
            KEY rule (rule),
            KEY dateline (dateline)
        ) ENGINE=MyISAM{$collation};");
    }

    if (!$db->table_exists('spam_preventer_cooldowns')) {
        $db->write_query("CREATE TABLE " . TABLE_PREFIX . "spam_preventer_cooldowns (
            cid int unsigned NOT NULL auto_increment,
            uid int unsigned NOT NULL default 0,
            dateline int unsigned NOT NULL default 0,
            expires int unsigned NOT NULL default 0,
            scope varchar(20) NOT NULL default 'threads',
            reason varchar(40) NOT NULL default 'rapid_threads',
            PRIMARY KEY (cid),
            KEY uid_expires (uid, expires)
        ) ENGINE=MyISAM{$collation};");
    }
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
        spam_preventer_setting('enabled', 'General: Enable Spam Preventer', 'Apply configured protections to eligible low-trust members.', 'yesno', '1', 10, $gid),
        spam_preventer_setting('log_hits', 'General: Log Rule Hits', 'Record each matched rule, selected action, user, forum, subject, and a short excerpt.', 'yesno', '1', 20, $gid),
        spam_preventer_setting('restricted_groups', 'Eligibility: Restricted Usergroup IDs', 'Comma-separated primary or additional usergroup IDs subject to these rules. MyBB Registered is group 2 by default. If your board uses a separate new-member group, enter that group ID here.', 'text', '2', 110, $gid),
        spam_preventer_setting('post_threshold', 'Eligibility: Minimum Posts for Exemption', 'Members remain restricted below this post count. Set to 0 to disable the post-count condition.', 'numeric', '25', 120, $gid),
        spam_preventer_setting('age_days', 'Eligibility: Minimum Account Age for Exemption', 'Members remain restricted until their account reaches this age in days. Set to 0 to disable the account-age condition.', 'numeric', '3', 130, $gid),
        spam_preventer_setting('block_links', 'External Links: Enable Rule', 'Check posts and threads for links that are not on the trusted-domain list.', 'yesno', '1', 210, $gid),
        spam_preventer_setting('link_action', 'External Links: Action', 'Choose what happens when this rule matches.', spam_preventer_action_options(), 'reject', 220, $gid),
        spam_preventer_setting('trusted_domains', 'External Links: Trusted Domains', 'One domain per line. Subdomains are trusted automatically. Use a leading wildcard for an entire suffix, such as *.edu. Do not include a protocol or path.', 'textarea', "sickgaming.net\ngithub.com\n*.edu\n*.gov", 230, $gid),
        spam_preventer_setting('block_phrases', 'Spam Phrases: Enable Rule', 'Check subjects and messages for configured phrases.', 'yesno', '1', 310, $gid),
        spam_preventer_setting('phrase_action', 'Spam Phrases: Action', 'Choose what happens when this rule matches.', spam_preventer_action_options(), 'reject', 320, $gid),
        spam_preventer_setting('phrases', 'Spam Phrases: Blocked Phrases', 'Enter one case-insensitive plain-text phrase per line. Blank lines and lines beginning with # are ignored.', 'textarea', spam_preventer_default_blocked_phrases(), 330, $gid),
        spam_preventer_setting('block_quote_only', 'Quote-Only Replies: Enable Rule', 'Require restricted members to add meaningful original text outside complete MyBB quote blocks. Applies to new replies, including Quick Reply, but not edits or new threads.', 'yesno', '1', 410, $gid),
        spam_preventer_setting('quote_action', 'Quote-Only Replies: Action', 'Choose what happens when this rule matches.', spam_preventer_action_options(), 'reject', 420, $gid),
        spam_preventer_setting('rapid_threads', 'Rapid Threads: Enable Cooldown', 'Temporarily restrict low-trust members who create too many new threads in a rolling window. Disabled by default.', 'yesno', '0', 510, $gid),
        spam_preventer_setting('rapid_thread_limit', 'Rapid Threads: Allowed Threads', 'Number of successfully created threads allowed in the rolling window before the next attempt triggers cooldown.', 'numeric', '3', 520, $gid),
        spam_preventer_setting('rapid_thread_window', 'Rapid Threads: Window Minutes', 'Rolling window length in minutes.', 'numeric', '15', 530, $gid),
        spam_preventer_setting('rapid_thread_cooldown', 'Rapid Threads: Cooldown Hours', 'Temporary restriction duration after the limit is exceeded.', 'numeric', '12', 540, $gid),
        spam_preventer_setting('rapid_thread_scope', 'Rapid Threads: Cooldown Scope', 'Choose whether cooldown blocks only new threads or all posts.', "select\nthreads=New threads only\nall=All posts and threads", 'threads', 550, $gid),
        spam_preventer_setting('ban_group', 'Ban Action: Target Usergroup ID', 'Usergroup assigned when a rule action is set to Ban user. MyBB Banned is usually group 7.', 'numeric', '7', 610, $gid),
        spam_preventer_setting('exempt_groups', 'Exemptions: Exempt Usergroup IDs', 'Comma-separated primary or additional usergroup IDs that bypass all rules. Administrators and forum moderators are always exempt.', 'text', '', 710, $gid),
        spam_preventer_setting('exempt_users', 'Exemptions: Exempt User IDs', 'Comma-separated user IDs that bypass all rules.', 'text', '', 720, $gid),
        spam_preventer_setting('exempt_forums', 'Exemptions: Exempt Forum IDs', 'Comma-separated forum IDs where the rules do not apply.', 'text', '', 730, $gid)
    );
}

function spam_preventer_action_options()
{
    return "select\nlog=Log only\nreject=Reject submission\nunapprove=Send to moderation queue\nban=Ban user and reject";
}

function spam_preventer_default_blocked_phrases()
{
    return implode("\n", array(
        'Ultrahuman coupon Code',
        'Ultrahuman Discount Code',
        'Ultrahuman promo code',
        'Ultrahuman referral code',
        'Lemfi coupon code',
        'Lemfi discount code',
        'Lemfi promo code',
        'Lemfi referral code',
        'Temu Coupon Code',
        'Temu Gutscheincode 30%',
        'Temu Gutscheincode 100',
        'Temu Gutscheincode for New Customers',
        'Temu Gutscheincode For New Users',
        'Temu Rabattcode für Neukunden',
        'Temu Gutschein für Neukunden',
        'SHEIN Coupon Code',
        'Ibotta Referral Code',
        'Ibotta Promo Code',
        'Ibotta Invite Code',
        'Ibotta Registration Bonus',
        'Ibotta Referral Program',
        'Insta360 Promo Code',
        'TℰℳU Coupon Code',
        'Apollo Neuro Coupon',
        'SHEIN Discount Code'
    ));
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
    $is_new_thread = spam_preventer_is_new_thread($datahandler, $data);

    if (!empty($mybb->settings['spam_preventer_rapid_threads'])
        && spam_preventer_cooldown_applies($datahandler, $data)) {
        $expires = spam_preventer_active_cooldown_expires((int)$mybb->user['uid']);
        if ($expires > TIME_NOW) {
            spam_preventer_apply_rule_action($datahandler, 'rapid_threads', 'reject', 'spam_preventer_rapid_threads_active', array(my_date('normal', $expires)), $data);
            return;
        }
    }

    if (!empty($mybb->settings['spam_preventer_rapid_threads']) && $is_new_thread) {
        $expires = spam_preventer_maybe_start_thread_cooldown((int)$mybb->user['uid'], $fid);
        if ($expires > TIME_NOW) {
            spam_preventer_apply_rule_action($datahandler, 'rapid_threads', 'reject', 'spam_preventer_rapid_threads_triggered', array(my_date('normal', $expires)), $data);
            return;
        }
    }

    if (!empty($mybb->settings['spam_preventer_block_links'])
        && spam_preventer_contains_untrusted_link($content, spam_preventer_lines($mybb->settings['spam_preventer_trusted_domains']))) {
        spam_preventer_apply_rule_action($datahandler, 'link', spam_preventer_rule_action('link'), 'spam_preventer_link', array($requirements), $data);
    }

    if (!empty($mybb->settings['spam_preventer_block_phrases'])
        && spam_preventer_contains_blocked_phrase($content, spam_preventer_lines($mybb->settings['spam_preventer_phrases'], true))) {
        spam_preventer_apply_rule_action($datahandler, 'phrase', spam_preventer_rule_action('phrase'), 'spam_preventer_phrase', array($requirements), $data);
    }

    if (!empty($mybb->settings['spam_preventer_block_quote_only'])
        && spam_preventer_is_new_reply($datahandler, $data)
        && !spam_preventer_has_original_reply_content($message)) {
        spam_preventer_apply_rule_action($datahandler, 'quote_only', spam_preventer_rule_action('quote'), 'spam_preventer_quote_only', array(), $data);
    }
}

function spam_preventer_is_new_reply($datahandler, $data)
{
    $method = isset($datahandler->method) ? strtolower((string)$datahandler->method) : '';
    return $method === 'insert' && !empty($data['tid']);
}

function spam_preventer_is_new_thread($datahandler, $data)
{
    $method = isset($datahandler->method) ? strtolower((string)$datahandler->method) : '';
    return $method === 'insert' && empty($data['tid']);
}

function spam_preventer_rule_action($rule)
{
    global $mybb;

    $setting = 'spam_preventer_' . $rule . '_action';
    $action = isset($mybb->settings[$setting]) ? $mybb->settings[$setting] : 'reject';

    return spam_preventer_normalize_action($action);
}

function spam_preventer_normalize_action($action)
{
    $action = strtolower((string)$action);
    return in_array($action, array('log', 'reject', 'unapprove', 'ban'), true) ? $action : 'reject';
}

function spam_preventer_apply_rule_action(&$datahandler, $rule, $action, $error_key, $error_args, $data)
{
    $action = spam_preventer_normalize_action($action);
    spam_preventer_log_hit($rule, $action, $data);

    if ($action === 'log') {
        return;
    }

    if ($action === 'unapprove') {
        $datahandler->data['visible'] = 0;
        return;
    }

    if ($action === 'ban') {
        spam_preventer_ban_current_user();
    }

    if ($error_args) {
        $datahandler->set_error($error_key, $error_args[0]);
    } else {
        $datahandler->set_error($error_key);
    }
}

function spam_preventer_ban_current_user()
{
    global $db, $mybb;

    $uid = isset($mybb->user['uid']) ? (int)$mybb->user['uid'] : 0;
    $ban_group = isset($mybb->settings['spam_preventer_ban_group']) ? max(0, (int)$mybb->settings['spam_preventer_ban_group']) : 7;
    if ($uid <= 0 || $ban_group <= 0) {
        return;
    }

    $db->update_query('users', array(
        'usergroup' => $ban_group,
        'displaygroup' => 0
    ), "uid='{$uid}'", 1);
    $mybb->user['usergroup'] = $ban_group;
}

function spam_preventer_log_hit($rule, $action, $data)
{
    global $db, $mybb, $session;

    if (empty($mybb->settings['spam_preventer_log_hits']) || !isset($db) || !method_exists($db, 'table_exists') || !$db->table_exists('spam_preventer_logs')) {
        return;
    }

    $message = isset($data['message']) ? (string)$data['message'] : '';
    $message = strip_tags($message);
    $message = preg_replace('~\s+~', ' ', $message);
    $db->insert_query('spam_preventer_logs', array(
        'uid' => isset($mybb->user['uid']) ? (int)$mybb->user['uid'] : 0,
        'username' => $db->escape_string(isset($mybb->user['username']) ? $mybb->user['username'] : ''),
        'fid' => isset($data['fid']) ? (int)$data['fid'] : 0,
        'rule' => $db->escape_string((string)$rule),
        'action' => $db->escape_string((string)$action),
        'subject' => $db->escape_string(my_substr(isset($data['subject']) ? (string)$data['subject'] : '', 0, 120)),
        'excerpt' => $db->escape_string(my_substr($message, 0, 255)),
        'dateline' => TIME_NOW,
        'ipaddress' => isset($session->packedip) ? $db->escape_binary($session->packedip) : ''
    ));
}

function spam_preventer_cooldown_applies($datahandler, $data)
{
    global $mybb;

    $scope = isset($mybb->settings['spam_preventer_rapid_thread_scope']) ? $mybb->settings['spam_preventer_rapid_thread_scope'] : 'threads';
    if ($scope === 'all') {
        return true;
    }

    return spam_preventer_is_new_thread($datahandler, $data);
}

function spam_preventer_active_cooldown_expires($uid)
{
    global $db;

    $uid = (int)$uid;
    if ($uid <= 0 || !isset($db) || !method_exists($db, 'table_exists') || !$db->table_exists('spam_preventer_cooldowns')) {
        return 0;
    }

    $query = $db->simple_select('spam_preventer_cooldowns', 'expires', "uid='{$uid}' AND expires>'" . TIME_NOW . "'", array('order_by' => 'expires', 'order_dir' => 'DESC', 'limit' => 1));
    return (int)$db->fetch_field($query, 'expires');
}

function spam_preventer_maybe_start_thread_cooldown($uid, $fid)
{
    global $db, $mybb;

    $uid = (int)$uid;
    $fid = (int)$fid;
    if ($uid <= 0 || !isset($db) || !method_exists($db, 'table_exists') || !$db->table_exists('spam_preventer_cooldowns')) {
        return 0;
    }

    $limit = isset($mybb->settings['spam_preventer_rapid_thread_limit']) ? max(1, min(100, (int)$mybb->settings['spam_preventer_rapid_thread_limit'])) : 3;
    $window_minutes = isset($mybb->settings['spam_preventer_rapid_thread_window']) ? max(1, min(1440, (int)$mybb->settings['spam_preventer_rapid_thread_window'])) : 15;
    $cooldown_hours = isset($mybb->settings['spam_preventer_rapid_thread_cooldown']) ? max(1, min(168, (int)$mybb->settings['spam_preventer_rapid_thread_cooldown'])) : 12;
    $scope = isset($mybb->settings['spam_preventer_rapid_thread_scope']) && $mybb->settings['spam_preventer_rapid_thread_scope'] === 'all' ? 'all' : 'threads';
    $cutoff = TIME_NOW - ($window_minutes * 60);
    $forum_exclusion = '';
    $exempt_forums = spam_preventer_id_list($mybb->settings['spam_preventer_exempt_forums']);
    if ($exempt_forums) {
        $forum_exclusion = ' AND fid NOT IN (' . implode(',', $exempt_forums) . ')';
    }

    $query = $db->simple_select('threads', 'COUNT(*) AS total', "uid='{$uid}' AND dateline>='{$cutoff}' AND visible>-1{$forum_exclusion}");
    $count = (int)$db->fetch_field($query, 'total');
    if ($count < $limit) {
        return 0;
    }

    $existing = spam_preventer_active_cooldown_expires($uid);
    if ($existing > TIME_NOW) {
        return $existing;
    }

    $expires = TIME_NOW + ($cooldown_hours * 3600);
    $db->insert_query('spam_preventer_cooldowns', array(
        'uid' => $uid,
        'dateline' => TIME_NOW,
        'expires' => $expires,
        'scope' => $db->escape_string($scope),
        'reason' => 'rapid_threads'
    ));

    return $expires;
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

    $asset_url = rtrim($mybb->asset_url, '/') . '/jscripts/spam-preventer/quick-reply-errors.js?ver=101';
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
