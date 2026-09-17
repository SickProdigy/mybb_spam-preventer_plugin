<?php

if (!defined('IN_MYBB') || !defined('IN_ADMINCP')) {
    die('Direct initialization of this file is not allowed.');
}

$lang->load('spam_preventer');
$baseUrl = 'index.php?module=tools-spam_preventer_logs';
$page->add_breadcrumb_item($lang->spam_preventer_logs, $baseUrl);

$subTabs = array(
    'spam_preventer_logs' => array(
        'title' => $lang->spam_preventer_logs,
        'link' => $baseUrl,
        'description' => $lang->spam_preventer_logs_desc
    ),
    'spam_preventer_prune_logs' => array(
        'title' => $lang->spam_preventer_prune_logs,
        'link' => $baseUrl . '&amp;action=prune',
        'description' => $lang->spam_preventer_prune_logs_desc
    )
);

function spam_preventer_admin_log_label($value)
{
    $labels = array(
        'link' => 'External link',
        'phrase' => 'Blocked phrase',
        'quote_only' => 'Quote-only reply',
        'rapid_threads' => 'Rapid threads',
        'log' => 'Log only',
        'reject' => 'Rejected',
        'unapprove' => 'Sent to moderation',
        'ban' => 'Banned and rejected'
    );

    return isset($labels[$value]) ? $labels[$value] : ucwords(str_replace('_', ' ', (string)$value));
}

function spam_preventer_admin_log_ip($packed)
{
    if ($packed === '' || $packed === null) {
        return '';
    }

    $ip = my_inet_ntop($packed);
    return $ip === false ? '' : $ip;
}

function spam_preventer_admin_log_filters()
{
    global $db, $mybb;

    $filters = array(
        'user' => trim($mybb->get_input('user')),
        'fid' => max(0, $mybb->get_input('fid', MyBB::INPUT_INT)),
        'rule' => trim($mybb->get_input('rule')),
        'log_action' => trim($mybb->get_input('log_action')),
        'date_from' => trim($mybb->get_input('date_from')),
        'date_to' => trim($mybb->get_input('date_to')),
        'perpage' => $mybb->get_input('perpage', MyBB::INPUT_INT)
    );
    if (!in_array($filters['rule'], array('', 'link', 'phrase', 'quote_only', 'rapid_threads'), true)) {
        $filters['rule'] = '';
    }
    if (!in_array($filters['log_action'], array('', 'log', 'reject', 'unapprove', 'ban'), true)) {
        $filters['log_action'] = '';
    }
    if ($filters['perpage'] < 10 || $filters['perpage'] > 100) {
        $filters['perpage'] = 25;
    }

    $conditions = array();
    if ($filters['user'] !== '') {
        if (ctype_digit($filters['user'])) {
            $conditions[] = 'l.uid=' . (int)$filters['user'];
        } else {
            $conditions[] = "l.username LIKE '%" . $db->escape_string_like($filters['user']) . "%'";
        }
    }
    if ($filters['fid'] > 0) {
        $conditions[] = 'l.fid=' . (int)$filters['fid'];
    }
    if ($filters['rule'] !== '') {
        $conditions[] = "l.rule='" . $db->escape_string($filters['rule']) . "'";
    }
    if ($filters['log_action'] !== '') {
        $conditions[] = "l.action='" . $db->escape_string($filters['log_action']) . "'";
    }
    foreach (array('date_from' => '>=', 'date_to' => '<') as $field => $operator) {
        if ($filters[$field] === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$field])) {
            continue;
        }
        $timestamp = strtotime($filters[$field] . ($field === 'date_to' ? ' +1 day' : ' 00:00:00'));
        if ($timestamp !== false) {
            $conditions[] = 'l.dateline' . $operator . (int)$timestamp;
        }
    }

    return array($filters, $conditions ? implode(' AND ', $conditions) : '1=1');
}

function spam_preventer_admin_log_query_string($filters)
{
    $values = array();
    foreach (array('user', 'fid', 'rule', 'log_action', 'date_from', 'date_to', 'perpage') as $field) {
        if (isset($filters[$field]) && $filters[$field] !== '' && $filters[$field] !== 0) {
            $values[$field] = $filters[$field];
        }
    }
    return str_replace('&', '&amp;', http_build_query($values, '', '&'));
}

if (!$db->table_exists('spam_preventer_logs')) {
    $page->output_header($lang->spam_preventer_logs);
    $page->output_nav_tabs($subTabs, 'spam_preventer_logs');
    $table = new Table;
    $table->construct_cell('The Spam Preventer log table is unavailable. Reactivate the plugin to create it.');
    $table->construct_row();
    $table->output($lang->spam_preventer_logs);
    $page->output_footer();
    exit;
}

$action = $mybb->get_input('action');

if ($action === 'delete_selected') {
    if ($mybb->request_method !== 'post') {
        admin_redirect($baseUrl);
    }
    verify_post_check($mybb->get_input('my_post_key'));
    if (!$mybb->get_input('confirm', MyBB::INPUT_INT)) {
        flash_message('Confirm deletion before removing selected log records.', 'error');
        admin_redirect($baseUrl);
    }
    $ids = isset($mybb->input['log_ids']) && is_array($mybb->input['log_ids']) ? $mybb->input['log_ids'] : array();
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        flash_message('Select at least one log record to delete.', 'error');
        admin_redirect($baseUrl);
    }
    $db->delete_query('spam_preventer_logs', 'lid IN (' . implode(',', $ids) . ')');
    $deleted = $db->affected_rows();
    log_admin_action('Spam Preventer logs', 'delete selected', $deleted);
    flash_message('Deleted ' . (int)$deleted . ' Spam Preventer log record(s).', 'success');
    admin_redirect($baseUrl);
}

if ($action === 'prune') {
    if ($mybb->request_method === 'post') {
        verify_post_check($mybb->get_input('my_post_key'));
        $days = $mybb->get_input('older_than', MyBB::INPUT_INT);
        if ($days < 1 || $days > 3650 || !$mybb->get_input('confirm', MyBB::INPUT_INT)) {
            flash_message('Choose an age from 1 to 3650 days and confirm permanent pruning.', 'error');
            admin_redirect($baseUrl . '&action=prune');
        }
        $cutoff = TIME_NOW - ($days * 86400);
        $db->delete_query('spam_preventer_logs', 'dateline<' . (int)$cutoff);
        $deleted = $db->affected_rows();
        log_admin_action('Spam Preventer logs', 'prune', $days, $deleted);
        flash_message('Pruned ' . (int)$deleted . ' Spam Preventer log record(s).', 'success');
        admin_redirect($baseUrl);
    }

    $page->add_breadcrumb_item($lang->spam_preventer_prune_logs, $baseUrl . '&amp;action=prune');
    $page->output_header($lang->spam_preventer_prune_logs);
    $page->output_nav_tabs($subTabs, 'spam_preventer_prune_logs');
    $form = new Form($baseUrl . '&amp;action=prune', 'post');
    $container = new FormContainer($lang->spam_preventer_prune_logs);
    $container->output_row('Age', 'Delete records older than this many days.', $form->generate_numeric_field('older_than', 90, array('min' => 1, 'max' => 3650)) . ' days');
    $container->output_row('Confirmation', 'Pruning is permanent and does not affect users, posts, or cooldowns.', $form->generate_check_box('confirm', 1, 'I understand these log records will be permanently deleted.'));
    $container->end();
    $form->output_submit_wrapper(array($form->generate_submit_button('Prune logs')));
    $form->end();
    $page->output_footer();
    exit;
}

if ($action === 'detail') {
    $id = $mybb->get_input('id', MyBB::INPUT_INT);
    $log = $db->fetch_array($db->query("SELECT l.*, f.name AS forum_name FROM " . TABLE_PREFIX . "spam_preventer_logs l LEFT JOIN " . TABLE_PREFIX . "forums f ON (f.fid=l.fid) WHERE l.lid=" . (int)$id . ' LIMIT 1'));
    if (!$log) {
        flash_message('The selected Spam Preventer log record does not exist.', 'error');
        admin_redirect($baseUrl);
    }
    $page->add_breadcrumb_item($lang->spam_preventer_log_detail, $baseUrl . '&amp;action=detail&amp;id=' . $id);
    $page->output_header($lang->spam_preventer_log_detail);
    $page->output_nav_tabs($subTabs, 'spam_preventer_logs');
    $table = new Table;
    $table->construct_header('Field', array('width' => '20%'));
    $table->construct_header('Value');
    $details = array(
        'Date' => my_date('normal', (int)$log['dateline']),
        'User' => $log['username'] . ' (UID ' . (int)$log['uid'] . ')',
        'Forum' => ($log['forum_name'] !== null ? $log['forum_name'] : 'Unknown') . ' (FID ' . (int)$log['fid'] . ')',
        'Rule' => spam_preventer_admin_log_label($log['rule']) . ' (' . $log['rule'] . ')',
        'Action' => spam_preventer_admin_log_label($log['action']) . ' (' . $log['action'] . ')',
        'Subject' => $log['subject'],
        'Excerpt' => $log['excerpt'],
        'IP address' => spam_preventer_admin_log_ip($log['ipaddress'])
    );
    foreach ($details as $label => $value) {
        $table->construct_cell(htmlspecialchars_uni($label));
        $table->construct_cell(nl2br(htmlspecialchars_uni((string)$value)));
        $table->construct_row();
    }
    $table->output($lang->spam_preventer_log_detail);
    echo '<p><a href="' . $baseUrl . '">&laquo; Return to Spam Preventer logs</a></p>';
    $page->output_footer();
    exit;
}

list($filters, $where) = spam_preventer_admin_log_filters();
$pageNumber = max(1, $mybb->get_input('page', MyBB::INPUT_INT));
$total = (int)$db->fetch_field($db->query('SELECT COUNT(*) AS total FROM ' . TABLE_PREFIX . 'spam_preventer_logs l WHERE ' . $where), 'total');
$pages = max(1, (int)ceil($total / $filters['perpage']));
if ($pageNumber > $pages) {
    $pageNumber = $pages;
}
$start = ($pageNumber - 1) * $filters['perpage'];

$page->output_header($lang->spam_preventer_logs);
$page->output_nav_tabs($subTabs, 'spam_preventer_logs');

$form = new Form($baseUrl, 'post');
$container = new FormContainer('Filter Spam Preventer logs');
$forumOptions = array(0 => 'All forums');
$forumQuery = $db->simple_select('forums', 'fid,name', "type='f'", array('order_by' => 'name'));
while ($forum = $db->fetch_array($forumQuery)) {
    $forumOptions[(int)$forum['fid']] = $forum['name'];
}
$ruleOptions = array('' => 'All rules', 'link' => 'External link', 'phrase' => 'Blocked phrase', 'quote_only' => 'Quote-only reply', 'rapid_threads' => 'Rapid threads');
$actionOptions = array('' => 'All actions', 'log' => 'Log only', 'reject' => 'Rejected', 'unapprove' => 'Sent to moderation', 'ban' => 'Banned and rejected');
$container->output_row('User', 'Enter an exact user ID or part of a username.', $form->generate_text_box('user', $filters['user']));
$container->output_row('Forum', '', $form->generate_select_box('fid', $forumOptions, $filters['fid']));
$container->output_row('Rule and action', '', $form->generate_select_box('rule', $ruleOptions, $filters['rule']) . ' ' . $form->generate_select_box('log_action', $actionOptions, $filters['log_action']));
$container->output_row('Date range', 'Use YYYY-MM-DD. Both boundaries are inclusive.', $form->generate_text_box('date_from', $filters['date_from'], array('placeholder' => 'From')) . ' ' . $form->generate_text_box('date_to', $filters['date_to'], array('placeholder' => 'To')));
$container->output_row('Results per page', 'Choose between 10 and 100.', $form->generate_numeric_field('perpage', $filters['perpage'], array('min' => 10, 'max' => 100)));
$container->end();
$form->output_submit_wrapper(array($form->generate_submit_button('Filter logs')));
$form->end();

$deleteForm = new Form($baseUrl . '&amp;action=delete_selected', 'post');
$table = new Table;
$table->construct_header('<input type="checkbox" onclick="var boxes=document.getElementsByName(\'log_ids[]\');for(var i=0;i&lt;boxes.length;i++){boxes[i].checked=this.checked;}">', array('width' => '1%', 'class' => 'align_center'));
$table->construct_header('Date', array('width' => '13%'));
$table->construct_header('User', array('width' => '14%'));
$table->construct_header('Forum', array('width' => '14%'));
$table->construct_header('Rule / action', array('width' => '16%'));
$table->construct_header('Submission');
$table->construct_header('IP address', array('width' => '12%'));
$table->construct_header('Details', array('width' => '6%', 'class' => 'align_center'));

$query = $db->query('SELECT l.*, f.name AS forum_name FROM ' . TABLE_PREFIX . 'spam_preventer_logs l LEFT JOIN ' . TABLE_PREFIX . 'forums f ON (f.fid=l.fid) WHERE ' . $where . ' ORDER BY l.dateline DESC, l.lid DESC LIMIT ' . (int)$start . ', ' . (int)$filters['perpage']);
while ($log = $db->fetch_array($query)) {
    $user = htmlspecialchars_uni($log['username'] !== '' ? $log['username'] : 'Guest');
    if ((int)$log['uid'] > 0) {
        $user = '<a href="index.php?module=user-users&amp;action=edit&amp;uid=' . (int)$log['uid'] . '">' . $user . '</a><br><small>UID ' . (int)$log['uid'] . '</small>';
    }
    $forum = htmlspecialchars_uni($log['forum_name'] !== null ? $log['forum_name'] : 'Unknown');
    if ((int)$log['fid'] > 0) {
        $forum .= '<br><small>FID ' . (int)$log['fid'] . '</small>';
    }
    $submission = '<strong>' . htmlspecialchars_uni($log['subject']) . '</strong>';
    if ($log['excerpt'] !== '') {
        $submission .= '<br><small>' . htmlspecialchars_uni(my_substr($log['excerpt'], 0, 160)) . '</small>';
    }
    $table->construct_cell('<input type="checkbox" name="log_ids[]" value="' . (int)$log['lid'] . '">', array('class' => 'align_center'));
    $table->construct_cell(my_date('normal', (int)$log['dateline']));
    $table->construct_cell($user);
    $table->construct_cell($forum);
    $table->construct_cell(htmlspecialchars_uni(spam_preventer_admin_log_label($log['rule'])) . '<br><small>' . htmlspecialchars_uni(spam_preventer_admin_log_label($log['action'])) . '</small>');
    $table->construct_cell($submission);
    $table->construct_cell(htmlspecialchars_uni(spam_preventer_admin_log_ip($log['ipaddress'])));
    $table->construct_cell('<a href="' . $baseUrl . '&amp;action=detail&amp;id=' . (int)$log['lid'] . '">View</a>', array('class' => 'align_center'));
    $table->construct_row();
}
if ($table->num_rows() === 0) {
    $table->construct_cell('No Spam Preventer log records match the selected filters.', array('colspan' => 8));
    $table->construct_row();
}
$table->output($lang->spam_preventer_logs . ' (' . $total . ')');

if ($total > 0) {
    echo '<p>' . $deleteForm->generate_check_box('confirm', 1, 'Confirm permanent deletion of the selected records.') . '</p>';
    $deleteForm->output_submit_wrapper(array($deleteForm->generate_submit_button('Delete selected')));
}
$deleteForm->end();

if ($total > $filters['perpage']) {
    $queryString = spam_preventer_admin_log_query_string($filters);
    echo draw_admin_pagination($pageNumber, $filters['perpage'], $total, $baseUrl . ($queryString !== '' ? '&amp;' . $queryString : ''));
}

$page->output_footer();
