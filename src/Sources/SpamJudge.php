<?php

/**
 * SpamJudge.php
 *
 * @package Spam Judge
 * @author  Bugo
 * @copyright 2026 Bugo
 * @license https://opensource.org/licenses/BSD-3-Clause BSD
 *
 * @version 0.1
 */

if (! defined('SMF'))
	die('No direct access...');

final class SpamJudge
{
	public function hooks(): void
	{
		add_integration_function('integrate_post_register', self::class . '::onRegister#', false);
		add_integration_function('integrate_after_create_post', self::class . '::onPost#', false);
		add_integration_function('integrate_admin_areas', self::class . '::adminAreas#', false);
	}

	public function onRegister(array $regOptions, array $theme_vars, int $memberID): void
	{
		global $modSettings, $user_info;

		if (
			empty($modSettings['spam_judge_enabled'])
			|| ! empty($user_info['is_admin'])
			|| ! empty($user_info['is_mod'])
		)
			return;

		if (empty($memberID))
			return;

		$content = trim(($regOptions['username'] ?? '') . "\n" . ($regOptions['email'] ?? ''));

		if ($content === '')
			return;

		$result = $this->classify($content);

		if ($result === null)
			return;

		[$verdict, $probabilities] = $result;

		$action = 'none';

		if ($verdict === 'spam'	&& $this->isSpamConfident($probabilities)) {
			$this->restrictMember($memberID);

			$action = 'restricted';
		}

		$this->log(
			'registration',
			$memberID,
			$regOptions['username'] ?? '',
			$regOptions['email'] ?? '',
			$content,
			$verdict,
			$probabilities,
			$action,
		);
	}

	public function onPost(array $msgOptions, array $topicOptions, array $posterOptions): void
	{
		global $modSettings, $user_info;

		if (
			empty($modSettings['spam_judge_enabled'])
			|| empty($modSettings['spam_judge_check_first_post'])
			|| ! empty($user_info['is_admin'])
			|| ! empty($user_info['is_mod'])
		)
			return;

		$memberID = $posterOptions['id'] ?? 0;

		if (empty($memberID) || $user_info['posts'] > (int) $modSettings['spam_judge_post_threshold'])
			return;

		$content = strip_tags($msgOptions['body'] ?? '');

		if ($content === '')
			return;

		$result = $this->classify($content);

		if ($result === null)
			return;

		[$verdict, $probabilities] = $result;

		$action = 'none';

		if ($verdict === 'spam' && $this->isSpamConfident($probabilities)) {
			$this->unapproveMessage((int) $msgOptions['id']);

			$action = 'unapproved';
		}

		$this->log(
			'post',
			$memberID,
			$user_info['username'] ?? '',
			$user_info['email'] ?? '',
			$content,
			$verdict,
			$probabilities,
			$action,
		);
	}

	public function adminAreas(array &$admin_areas): void
	{
		global $sourcedir, $txt;

		loadLanguage('SpamJudge');

		require_once($sourcedir . '/ManageSettings.php');

		$admin_areas['config']['areas']['spamjudge'] = [
			'label'    => $txt['spam_judge'],
			'function' => $this->main(...),
			'icon'     => 'security',
			'subsections' => [
				'settings' => [$txt['settings']],
				'logs'     => [$txt['logs']],
			],
		];
	}

	public function main(): void
	{
		global $context, $txt;

		$subActions = [
			'settings' => 'settings',
			'logs'     => 'logs',
		];

		loadGeneralSettingParameters($subActions, 'settings');

		$context[$context['admin_menu_name']]['tab_data'] = [
			'title' => $txt['spam_judge'],
			'tabs'  => [
				'settings' => [
					'description' => $txt['spam_judge_settings_description'],
				],
				'logs' => [
					'description' => $txt['spam_judge_logs_description'],
				],
			],
		];

		match ($_REQUEST['sa']) {
			'logs'  => $this->logs(),
			default => $this->settings(),
		};
	}

	/**
	 * @return void|array
	 */
	public function settings(bool $return_config = false)
	{
		global $context, $modSettings, $txt, $scripturl;

		if (empty($modSettings['spam_judge_model'])) {
			$modSettings['spam_judge_model'] = 'jev-latest';
		}

		$modSettings['spam_judge_spam_threshold'] ??= 0.95;

		$groups = $this->getGroups();

		$config_vars = [
			['check', 'spam_judge_enabled'],
			['text', 'spam_judge_gateway_url', 60],
			['text', 'spam_judge_api_key', 60],
			['text', 'spam_judge_model', 60],
			['float', 'spam_judge_spam_threshold', 'step' => 0.01, 'max' => 1],
			['check', 'spam_judge_check_first_post'],
			['int', 'spam_judge_post_threshold'],
			['select', 'spam_judge_restricted_group', $groups],
		];

		$context['page_title']     = $txt['spam_judge'];
		$context['settings_title'] = $txt['settings'];
		$context['post_url']       = $scripturl . '?action=admin;area=spamjudge;save;sa=settings';

		if ($return_config) {
			return $config_vars;
		}

		if (isset($_GET['save'])) {
			checkSession();
			saveDBSettings($config_vars);
			redirectexit('action=admin;area=spamjudge;sa=settings');
		}

		prepareDBSettingContext($config_vars);
	}

	public function logs(): void
	{
		global $context, $sourcedir, $txt, $scripturl;

		$context['page_title'] = $txt['logs'];
		$actionLabels = [
			'none'       => $txt['spam_judge_action_none'],
			'restricted' => $txt['spam_judge_action_restricted'],
			'unapproved' => $txt['spam_judge_action_unapproved'],
		];

		require_once($sourcedir . '/Subs-List.php');

		createList([
			'id'               => 'spam_judge_logs_list',
			'title'            => $txt['logs'],
			'no_items_label'   => $txt['spam_judge_no_items'],
			'items_per_page'   => 20,
			'base_href'        => $scripturl . '?action=admin;area=spamjudge;sa=logs',
			'default_sort_col' => 'time',
			'get_items'        => ['function' => $this->listGetLogs(...)],
			'get_count'        => ['function' => $this->listGetCount(...)],
			'columns'          => [
				'time' => [
					'header' => ['value' => $txt['date']],
					'data'   => ['function' => static fn($row) => timeformat($row['log_time'])],
					'sort'   => ['default' => 'log_time DESC', 'reverse' => 'log_time'],
				],
				'type' => [
					'header' => ['value' => $txt['spam_judge_type']],
					'data'   => ['db' => 'action_type'],
				],
				'member' => [
					'header' => ['value' => $txt['who_member']],
					'data'   => ['function' => static fn($row) => htmlspecialchars($row['member_name']) . ' (' . $row['ip'] . ')'],
				],
				'verdict' => [
					'header' => ['value' => $txt['spam_judge_verdict']],
					'data'   => ['function' => static fn($row) => htmlspecialchars($row['verdict']) . ' — ' . htmlspecialchars($row['probabilities'])],
				],
				'action' => [
					'header' => ['value' => $txt['spam_judge_action']],
					'data'   => [
						'function' => static fn($row) => $actionLabels[$row['action_taken']] ?? $row['action_taken'],
					],
				],
			],
		]);

		$context['sub_template'] = 'show_list';
		$context['default_list'] = 'spam_judge_logs_list';
	}

	public function listGetLogs(int $start, int $items, string $sort): array
	{
		global $smcFunc;

		$query = $smcFunc['db_query']('', "
			SELECT *
			FROM {db_prefix}spam_judge_logs
			ORDER BY $sort
			LIMIT {int:start}, {int:items}",
			['start' => $start, 'items' => $items]
		);

		$rows = [];
		while ($row = $smcFunc['db_fetch_assoc']($query)) {
			$rows[] = $row;
		}

		$smcFunc['db_free_result']($query);

		return $rows;
	}

	public function listGetCount(): int
	{
		global $smcFunc;

		$query = $smcFunc['db_query']('', /** @lang text */ 'SELECT COUNT(*) AS total FROM {db_prefix}spam_judge_logs');
		$row   = $smcFunc['db_fetch_assoc']($query);

		$smcFunc['db_free_result']($query);

		return (int) $row['total'];
	}

	private function classify(string $content): ?array
	{
		global $modSettings, $txt;

		loadLanguage('SpamJudge');

		$gateway = trim($modSettings['spam_judge_gateway_url'] ?? '');
		$apiKey  = trim($modSettings['spam_judge_api_key'] ?? '');
		$model   = trim($modSettings['spam_judge_model'] ?? '') ?: 'jev-latest';

		if ($gateway === '' || $apiKey === '') {
			$this->logError($txt['spam_judge_error_missing_config']);

			return null;
		}

		try {
			$payload = json_encode([
				'model' => $model,
				'state' => [
					'content' => mb_substr($content, 0, 10000),
				],
				'questions' => [
					'classification' => [
						'type' => 'choice',
						'instructions' => 'Classify the supplied forum content for anti-spam moderation. '
							. 'Choose spam only for clearly unsolicited or deceptive promotional content. '
							. 'Choose ham for legitimate user content. '
							. 'Choose review when the content is ambiguous or there is not enough evidence.',
						'criteria' => [
							'ham'    => 'Legitimate forum content, including ordinary discussion, questions, and relevant links.',
							'spam'   => 'Unsolicited or deceptive promotion, link farming, or automated spam.',
							'review' => 'Ambiguous content or insufficient evidence to confidently classify as ham or spam.',
						],
					],
				],
			], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			$this->logError($txt['spam_judge_error_encode']);

			return null;
		}

		$ch = curl_init($gateway);

		if ($ch === false) {
			$this->logError($txt['spam_judge_error_curl_init']);

			return null;
		}

		curl_setopt_array($ch, [
			CURLOPT_POST           => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT        => 60,
			CURLOPT_HTTPHEADER     => [
				'Content-Type: application/json',
				'Authorization: Bearer ' . $apiKey,
			],
			CURLOPT_POSTFIELDS => $payload,
		]);

		$response = curl_exec($ch);
		$error    = curl_error($ch);
		$status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		curl_close($ch);

		if ($response === false) {
			$this->logError(sprintf($txt['spam_judge_error_request'], $error));

			return null;
		}

		if ($status < 200 || $status >= 300) {
			$this->logError(sprintf($txt['spam_judge_error_http'], $status));

			return null;
		}

		return $this->parseResponse($response);
	}

	private function parseResponse(string $response): ?array
	{
		global $txt;

		try {
			$data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			$this->logError($txt['spam_judge_error_invalid_json']);

			return null;
		}

		if (! is_array($data)) {
			$this->logError($txt['spam_judge_error_invalid_response']);

			return null;
		}

		$answer = $data['answers']['classification'] ?? null;

		if (
			! is_array($answer)
			|| ($answer['type'] ?? null) !== 'choice'
			|| ! in_array($answer['choice'] ?? null, ['ham', 'spam', 'review'], true)
			|| empty($answer['probabilities'])
			|| ! is_array($answer['probabilities'])
		) {
			$this->logError($txt['spam_judge_error_invalid_answer']);

			return null;
		}

		$probabilities = $answer['probabilities'];

		foreach (['ham', 'spam', 'review'] as $option) {
			if (
				! isset($probabilities[$option])
				|| ! is_numeric($probabilities[$option])
				|| ! is_finite((float) $probabilities[$option])
				|| (float) $probabilities[$option] < 0
				|| (float) $probabilities[$option] > 1
			) {
				$this->logError($txt['spam_judge_error_invalid_probabilities']);

				return null;
			}

			$probabilities[$option] = (float) $probabilities[$option];
		}

		return [$answer['choice'], $probabilities];
	}

	private function logError(string $message): void
	{
		global $txt;

		log_error($txt['spam_judge'] . ': ' . $message);
	}

	private function isSpamConfident(array $probabilities): bool
	{
		global $modSettings;

		$threshold  = (float) ($modSettings['spam_judge_spam_threshold'] ?? 0.95);
		$confidence = (float) ($probabilities['spam'] ?? 0);

		return $confidence >= $threshold;
	}

	private function restrictMember(int $memberID): void
	{
		global $smcFunc, $modSettings;

		$groupID = (int) ($modSettings['spam_judge_restricted_group'] ?? 0);

		if (empty($groupID))
			return;

		$smcFunc['db_query']('', '
			UPDATE {db_prefix}members
			SET id_group = {int:group}
			WHERE id_member = {int:member}',
			[
				'group'  => $groupID,
				'member' => $memberID,
			]
		);
	}

	private function unapproveMessage(int $msgID): void
	{
		global $smcFunc;

		$smcFunc['db_query']('', '
			UPDATE {db_prefix}messages
			SET approved = {int:approved}
			WHERE id_msg = {int:msg}',
			[
				'approved' => 0,
				'msg'      => $msgID,
			]
		);
	}

	private function log(
		string $type,
		int $memberID,
		string $name,
		string $email,
		string $content,
		string $verdict,
		array $probabilities,
		string $action,
	): void	{
		global $smcFunc;

		$smcFunc['db_insert']('insert',
			'{db_prefix}spam_judge_logs',
			[
				'log_time' => 'int', 'action_type' => 'string', 'id_member' => 'int',
				'member_name' => 'string', 'email_address' => 'string', 'ip' => 'string',
				'content_snippet' => 'string', 'verdict' => 'string', 'probabilities' => 'string',
				'action_taken' => 'string',
			],
			[
				time(), $type, $memberID, $name, $email, $_SERVER['REMOTE_ADDR'] ?? '',
				mb_substr($content, 0, 500), $verdict, json_encode($probabilities), $action,
			],
			['id_log']
		);
	}

	private function getGroups(): array
	{
		global $smcFunc, $txt;

		$groups = [0 => $txt['spam_judge_no_group'] ?? '— none, do not restrict —'];

		$query = $smcFunc['db_query']('', /** @lang text */ '
			SELECT id_group, group_name
			FROM {db_prefix}membergroups
			WHERE id_group NOT IN (1, 3)
			ORDER BY group_name'
		);

		while ($row = $smcFunc['db_fetch_assoc']($query)) {
			$groups[$row['id_group']] = $row['group_name'];
		}

		$smcFunc['db_free_result']($query);

		return $groups;
	}
}
