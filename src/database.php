<?php

if (file_exists(dirname(__FILE__) . '/SSI.php') && ! defined('SMF'))
	require_once(dirname(__FILE__) . '/SSI.php');
elseif (! defined('SMF'))
	die('<b>Error:</b> Cannot install - please verify that you put this file in the same place as SMF\'s index.php and SSI.php files.');

if ((SMF === 'SSI') && ! $user_info['is_admin'])
	die('Admin privileges required.');

$tables[] = [
	'name' => 'spam_judge_logs',
	'columns' => [
		['name' => 'id_log', 'type' => 'mediumint', 'size' => 8, 'unsigned' => true, 'auto' => true],
		['name' => 'log_time', 'type' => 'int', 'size' => 10, 'unsigned' => true, 'default' => 0],
		['name' => 'action_type', 'type' => 'varchar', 'size' => 20, 'default' => ''],
		['name' => 'id_member', 'type' => 'mediumint', 'size' => 8, 'unsigned' => true, 'default' => 0],
		['name' => 'member_name', 'type' => 'varchar', 'size' => 80, 'default' => ''],
		['name' => 'email_address', 'type' => 'varchar', 'size' => 255, 'default' => ''],
		['name' => 'ip', 'type' => 'varchar', 'size' => 45, 'default' => ''],
		['name' => 'content_snippet', 'type' => 'text', 'null' => true],
		['name' => 'verdict', 'type' => 'varchar', 'size' => 20, 'default' => ''],
		['name' => 'probabilities', 'type' => 'text', 'null' => true],
		['name' => 'action_taken', 'type' => 'varchar', 'size' => 50, 'default' => ''],
	],
	'indexes' => [
		['type' => 'primary', 'columns' => ['id_log']],
		['type' => 'index', 'columns' => ['log_time']],
		['type' => 'index', 'columns' => ['id_member']],
	],
];

db_extend('packages');

foreach ($tables as $table) {
	$smcFunc['db_create_table']('{db_prefix}' . $table['name'], $table['columns'], $table['indexes'], [], 'ignore');
}

if (SMF === 'SSI')
	echo 'Database changes are complete! Please wait...';
