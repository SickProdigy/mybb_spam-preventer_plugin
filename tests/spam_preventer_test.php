<?php

define('IN_MYBB', 1);
define('TIME_NOW', 2000000000);

class SpamPreventerTestPlugins
{
    public function add_hook($hook, $callback)
    {
    }
}

class SpamPreventerTestDataHandler
{
    public $method = 'insert';
    public $data = array();
    public $errors = array();

    public function set_error($error, $data = '')
    {
        $this->errors[] = array($error, $data);
    }
}

function is_super_admin($uid)
{
    return $uid === 1;
}

function is_moderator($fid, $permission = '', $uid = 0)
{
    return $uid === 9;
}

$plugins = new SpamPreventerTestPlugins();
require dirname(__DIR__) . '/Upload/inc/plugins/spam_preventer.php';

function spam_preventer_test_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$trusted = array('sickgaming.net', 'github.com', '*.edu', '*.gov');

spam_preventer_test_assert(
    !spam_preventer_contains_untrusted_link('This is an ordinary post about MyBB 1.8.', $trusted),
    'ordinary text should be allowed'
);
spam_preventer_test_assert(
    !spam_preventer_contains_untrusted_link('Visit https://sickgaming.net/forums today.', $trusted),
    'the exact trusted domain should be allowed'
);
spam_preventer_test_assert(
    !spam_preventer_contains_untrusted_link('Visit https://cdn.sickgaming.net/file today.', $trusted),
    'subdomains of a trusted domain should be allowed'
);
spam_preventer_test_assert(
    !spam_preventer_contains_untrusted_link('View the project at https://github.com/sickprodigy/example.', $trusted),
    'GitHub should be allowed by default'
);
spam_preventer_test_assert(
    !spam_preventer_contains_untrusted_link('Read https://engineering.mit.edu/research.', $trusted),
    'an edu domain and its subdomains should be allowed by a wildcard suffix'
);
spam_preventer_test_assert(
    !spam_preventer_contains_untrusted_link('Read https://www.nasa.gov/news.', $trusted),
    'a gov domain and its subdomains should be allowed by a wildcard suffix'
);
spam_preventer_test_assert(
    spam_preventer_contains_untrusted_link('Visit https://example.education now.', $trusted),
    'a lookalike edu suffix should remain blocked'
);
spam_preventer_test_assert(
    spam_preventer_contains_untrusted_link('Visit https://example.gov.com now.', $trusted),
    'a gov label under an untrusted domain should remain blocked'
);
spam_preventer_test_assert(
    spam_preventer_contains_untrusted_link('Visit https://example.com now.', $trusted),
    'an external URL should be blocked'
);
spam_preventer_test_assert(
    spam_preventer_contains_untrusted_link('[url=https://example.com]Example[/url]', $trusted),
    'a MyCode URL should be blocked'
);
spam_preventer_test_assert(
    spam_preventer_contains_untrusted_link('Try hxxps://example[dot]com/deal', $trusted),
    'an obfuscated URL should be blocked'
);
spam_preventer_test_assert(
    spam_preventer_contains_blocked_phrase(
        'SHEIN Coupon Code 50% Off For New Users',
        array('shein coupon code')
    ),
    'phrase matching should be case-insensitive'
);
spam_preventer_test_assert(
    !spam_preventer_contains_blocked_phrase('A normal gaming discussion', array('coupon code')),
    'unmatched content should be allowed'
);
spam_preventer_test_assert(
    spam_preventer_lines("# explanation\nblocked phrase\n\nsecond phrase", true) === array('blocked phrase', 'second phrase'),
    'blank lines and comments should be ignored'
);
spam_preventer_test_assert(
    spam_preventer_id_list('2, 4 4,invalid,9') === array(2, 4, 9),
    'ID lists should be normalized and deduplicated'
);
spam_preventer_test_assert(
    !spam_preventer_has_original_reply_content("[quote='User' pid='123']Quoted post[/quote]"),
    'a single attributed quote should not count as original content'
);
spam_preventer_test_assert(
    !spam_preventer_has_original_reply_content(" [quote]First[/quote]\n[quote]Second [quote]nested[/quote][/quote] [b] [/b]"),
    'multiple and nested quotes with formatting-only residue should be rejected'
);
spam_preventer_test_assert(
    !spam_preventer_has_original_reply_content("[quote]Quoted[/quote]\n[color=red][i]&nbsp;[/i][/color]"),
    'entities inside empty formatting tags should not count as original content'
);
spam_preventer_test_assert(
    spam_preventer_has_original_reply_content("[quote]Quoted[/quote]\nI agree with this."),
    'meaningful text outside a quote should be accepted'
);
spam_preventer_test_assert(
    spam_preventer_has_original_reply_content("Before [quote]Quoted [quote]nested[/quote][/quote] after"),
    'original text surrounding a nested quote should be retained'
);

$insert_reply_handler = (object)array('method' => 'insert');
$update_reply_handler = (object)array('method' => 'update');
spam_preventer_test_assert(
    spam_preventer_is_new_reply($insert_reply_handler, array('tid' => 12)),
    'an inserted post with a thread ID should be treated as a new reply'
);
spam_preventer_test_assert(
    !spam_preventer_is_new_reply($update_reply_handler, array('tid' => 12)),
    'an edited reply should not be subject to the quote-only rule'
);
spam_preventer_test_assert(
    !spam_preventer_is_new_reply($insert_reply_handler, array()),
    'a new thread should not be subject to the quote-only rule'
);
spam_preventer_test_assert(
    spam_preventer_is_new_thread($insert_reply_handler, array()),
    'an inserted handler without a thread ID should be treated as a new thread'
);
spam_preventer_test_assert(
    !spam_preventer_is_new_thread($insert_reply_handler, array('tid' => 12)),
    'an inserted handler with a thread ID should not be treated as a new thread'
);
spam_preventer_test_assert(
    !spam_preventer_is_new_thread($update_reply_handler, array()),
    'an edited thread should not trigger new-thread cooldown checks'
);
spam_preventer_test_assert(
    spam_preventer_normalize_action('log') === 'log',
    'log should be a valid rule action'
);
spam_preventer_test_assert(
    spam_preventer_normalize_action('unapprove') === 'unapprove',
    'unapprove should be a valid rule action'
);
spam_preventer_test_assert(
    spam_preventer_normalize_action('ban') === 'ban',
    'ban should be a valid rule action'
);
spam_preventer_test_assert(
    spam_preventer_normalize_action('unexpected') === 'reject',
    'unknown rule actions should fall back to reject'
);
spam_preventer_test_assert(
    in_array('Temu Coupon Code', spam_preventer_lines(spam_preventer_default_blocked_phrases(), true), true),
    'default blocked phrases should include recurring Temu coupon spam'
);
spam_preventer_test_assert(
    in_array('TℰℳU Coupon Code', spam_preventer_lines(spam_preventer_default_blocked_phrases(), true), true),
    'default blocked phrases should include styled-character Temu coupon spam'
);
$default_phrase_file = trim(file_get_contents(dirname(__DIR__) . '/default-blocked-phrases.txt'));
spam_preventer_test_assert(
    $default_phrase_file === spam_preventer_default_blocked_phrases(),
    'default blocked phrase file should match the plugin default setting value'
);

$mybb = (object)array(
    'settings' => array(
        'spam_preventer_exempt_users' => '',
        'spam_preventer_exempt_forums' => '',
        'spam_preventer_exempt_groups' => '',
        'spam_preventer_restricted_groups' => '2',
        'spam_preventer_post_threshold' => '25',
        'spam_preventer_age_days' => '3',
        'spam_preventer_rapid_thread_scope' => 'threads',
        'spam_preventer_log_hits' => '0',
        'spam_preventer_link_action' => 'reject'
    ),
    'usergroup' => array('cancp' => 0)
);

$action_handler = new SpamPreventerTestDataHandler();
spam_preventer_apply_rule_action($action_handler, 'link', 'log', 'spam_preventer_link', array('requirements'), array());
spam_preventer_test_assert(
    empty($action_handler->errors),
    'log-only actions should allow the submission'
);

$action_handler = new SpamPreventerTestDataHandler();
spam_preventer_apply_rule_action($action_handler, 'link', 'unapprove', 'spam_preventer_link', array('requirements'), array());
spam_preventer_test_assert(
    empty($action_handler->errors) && isset($action_handler->data['visible']) && $action_handler->data['visible'] === 0,
    'unapprove actions should place the submission in the moderation queue'
);

$action_handler = new SpamPreventerTestDataHandler();
spam_preventer_apply_rule_action($action_handler, 'link', 'reject', 'spam_preventer_link', array('requirements'), array());
spam_preventer_test_assert(
    $action_handler->errors === array(array('spam_preventer_link', 'requirements')),
    'reject actions should add the configured validation error'
);

spam_preventer_test_assert(
    spam_preventer_rule_action('link') === 'reject',
    'rule actions should be read from their matching settings'
);

spam_preventer_test_assert(
    spam_preventer_cooldown_applies($insert_reply_handler, array()),
    'thread-scoped cooldowns should apply to new threads'
);
spam_preventer_test_assert(
    !spam_preventer_cooldown_applies($insert_reply_handler, array('tid' => 12)),
    'thread-scoped cooldowns should not apply to new replies'
);

$mybb->settings['spam_preventer_rapid_thread_scope'] = 'all';
spam_preventer_test_assert(
    spam_preventer_cooldown_applies($insert_reply_handler, array('tid' => 12)),
    'all-post cooldowns should apply to replies'
);
$mybb->settings['spam_preventer_rapid_thread_scope'] = 'threads';

$new_member = array(
    'uid' => 10,
    'usergroup' => 2,
    'additionalgroups' => '',
    'postnum' => 24,
    'regdate' => TIME_NOW - (10 * 86400)
);
spam_preventer_test_assert(
    spam_preventer_should_restrict($new_member, 5),
    'a member below 25 posts should remain restricted'
);

$new_member['postnum'] = 25;
$new_member['regdate'] = TIME_NOW - (2 * 86400);
spam_preventer_test_assert(
    spam_preventer_should_restrict($new_member, 5),
    'a 25-post account younger than three days should remain restricted'
);

$new_member['regdate'] = TIME_NOW - (4 * 86400);
spam_preventer_test_assert(
    !spam_preventer_should_restrict($new_member, 5),
    'a member meeting both thresholds should be exempt'
);

$trusted_member = $new_member;
$trusted_member['usergroup'] = 8;
$trusted_member['postnum'] = 0;
$trusted_member['regdate'] = TIME_NOW;
spam_preventer_test_assert(
    !spam_preventer_should_restrict($trusted_member, 5),
    'a user outside configured restricted groups should be exempt'
);

$moderator = $new_member;
$moderator['uid'] = 9;
$moderator['postnum'] = 0;
$moderator['regdate'] = TIME_NOW;
spam_preventer_test_assert(
    !spam_preventer_should_restrict($moderator, 5),
    'forum moderators should be exempt'
);

echo "Spam Preventer tests passed.\n";
