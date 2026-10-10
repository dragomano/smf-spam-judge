<?php

/**
 * @package Spam Judge
 * @author  Bugo
 * @copyright 2026 Bugo
 * @license https://opensource.org/licenses/BSD-3-Clause BSD
 *
 * @version 0.2
 */

namespace Bugo\SpamJudge;

use Bugo\SpamJudge\Http\CurlInitException;
use Bugo\SpamJudge\Http\HttpClient;
use Bugo\SpamJudge\Http\HttpClientException;
use Bugo\SpamJudge\Http\HttpStatusException;
use Bugo\SpamJudge\Http\InvalidJsonException;
use Bugo\SpamJudge\Strategy\InvalidAnswerException;
use Bugo\SpamJudge\Strategy\InvalidProbabilitiesException;
use Bugo\SpamJudge\Strategy\InvalidResponseException;
use Bugo\SpamJudge\Strategy\OpenAiStrategy;
use Bugo\SpamJudge\Strategy\Result;
use Bugo\SpamJudge\Strategy\StrategyInterface;
use Bugo\SpamJudge\Strategy\SystemOneStrategy;
use JsonException;

if (! defined('SMF'))
	die('No direct access...');

final class Integration
{
	private const NAME = 'Spam Judge';

	public function hooks(): void
	{
		add_integration_function('integrate_autoload', self::class . '::autoload#', false);
		add_integration_function('integrate_post_register', self::class . '::onRegister#', false);
		add_integration_function('integrate_after_create_post', self::class . '::onPost#', false);
		add_integration_function('integrate_admin_areas', self::class . '::adminAreas#', false);
	}

	public function autoload(array &$classMap): void
	{
		$classMap['Bugo\\SpamJudge\\'] = 'SpamJudge/';
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

		$verdict       = $result->verdict;
		$probabilities = $result->probabilities;

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

		$verdict       = $result->verdict;
		$probabilities = $result->probabilities;

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

		loadLanguage('SpamJudge/');

		require_once($sourcedir . '/ManageSettings.php');

		$admin_areas['config']['areas']['spamjudge'] = [
			'label'    => self::NAME,
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
			'title' => self::NAME,
			'tabs'  => [
				'settings' => [
					'description' => $txt['spam_judge_settings_description'],
				],
				'logs' => [
					'description' => sprintf($txt['spam_judge_logs_description'], self::NAME),
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

		$apiType = ApiType::tryFrom($modSettings['spam_judge_api_type'] ?? '') ?? ApiType::SystemOne;

		if (empty($modSettings['spam_judge_model'])) {
			$modSettings['spam_judge_model'] = $apiType->defaultModel();
		}

		$modSettings['spam_judge_spam_threshold'] ??= 0.95;

		$txt['spam_judge_enabled'] = sprintf($txt['spam_judge_enabled'], self::NAME);

		$groups = $this->getGroups();

		$config_vars = [
			['check', 'spam_judge_enabled'],
			['select', 'spam_judge_api_type', ApiType::options()],
			['text', 'spam_judge_gateway_url', 60, 'desc' => $txt['spam_judge_gateway_url_desc']],
			['text', 'spam_judge_api_key', 60],
			['text', 'spam_judge_model', 60],
			['float', 'spam_judge_spam_threshold', 'step' => 0.01, 'max' => 1],
			['check', 'spam_judge_check_first_post'],
			['int', 'spam_judge_post_threshold'],
			['select', 'spam_judge_restricted_group', $groups],
		];

		$context['page_title']     = self::NAME;
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

	private function classify(string $content): ?Result
	{
		global $modSettings, $txt;

		loadLanguage('SpamJudge/');

		$gateway = trim($modSettings['spam_judge_gateway_url'] ?? '');
		$apiKey  = trim($modSettings['spam_judge_api_key'] ?? '');

		if ($gateway === '' || $apiKey === '') {
			$this->logError($txt['spam_judge_error_missing_config']);

			return null;
		}

		$apiType = ApiType::tryFrom($modSettings['spam_judge_api_type'] ?? '') ?? ApiType::SystemOne;
		$model   = trim($modSettings['spam_judge_model'] ?? '') ?: $apiType->defaultModel();

		try {
			return $this->strategy($apiType, $gateway, $apiKey, $model)->classify($content);
		} catch (JsonException) {
			$this->logError($txt['spam_judge_error_encode']);
		} catch (CurlInitException) {
			$this->logError($txt['spam_judge_error_curl_init']);
		} catch (HttpStatusException $e) {
			$this->logError(sprintf($txt['spam_judge_error_http'], $e->status));
		} catch (InvalidJsonException) {
			$this->logError($txt['spam_judge_error_invalid_json']);
		} catch (HttpClientException $e) {
			$this->logError(sprintf($txt['spam_judge_error_request'], $e->getMessage()));
		} catch (InvalidAnswerException) {
			$this->logError($txt['spam_judge_error_invalid_answer']);
		} catch (InvalidProbabilitiesException) {
			$this->logError($txt['spam_judge_error_invalid_probabilities']);
		} catch (InvalidResponseException) {
			$this->logError($txt['spam_judge_error_invalid_response']);
		}

		return null;
	}

	private function strategy(ApiType $apiType, string $gateway, string $apiKey, string $model): StrategyInterface
	{
		return match ($apiType) {
			ApiType::OpenAi    => new OpenAiStrategy(new HttpClient(), $gateway, $apiKey, $model),
			ApiType::SystemOne => new SystemOneStrategy(new HttpClient(), $gateway, $apiKey, $model),
		};
	}

	private function logError(string $message): void
	{
		log_error(self::NAME . ': ' . $message);
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
				'log_time'        => 'int',
				'action_type'     => 'string',
				'id_member'       => 'int',
				'member_name'     => 'string',
				'email_address'   => 'string',
				'ip'              => 'string',
				'content_snippet' => 'string',
				'verdict'         => 'string',
				'probabilities'   => 'string',
				'action_taken'    => 'string',
			],
			[
				time(),
				$type,
				$memberID,
				$name,
				$email,
				$_SERVER['REMOTE_ADDR'] ?? '',
				mb_substr($content, 0, 500),
				$verdict,
				json_encode($probabilities),
				$action,
			],
			['id_log']
		);
	}

	private function getGroups(): array
	{
		global $smcFunc, $txt;

		$groups = [0 => $txt['spam_judge_no_group']];

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
