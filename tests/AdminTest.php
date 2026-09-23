<?php

declare(strict_types=1);

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

require_once __DIR__ . '/Environment.php';

#[Test]
#[Covers(SpamJudge::class)]
final class AdminTest
{
    public function settingsReturnsConfigurationAndUsesDefaultModel(): void
    {
        Environment::reset();
        $GLOBALS['sourcedir'] = sys_get_temp_dir();
        $GLOBALS['context'] = [];
        $GLOBALS['txt'] += ['settings' => 'Settings'];
        $GLOBALS['scripturl'] = 'https://example.test/index.php';

        $config = (new SpamJudge())->settings(true);

        Assert::same($GLOBALS['modSettings']['spam_judge_model'], 'jev-latest');
        Assert::same($GLOBALS['modSettings']['spam_judge_spam_threshold'], 0.95);
        Assert::same($config[0], ['check', 'spam_judge_enabled']);
        Assert::same($config[7][1], 'spam_judge_restricted_group');
    }

    public function settingsPreparesContextWhenNotSaving(): void
    {
        Environment::reset();
        $GLOBALS['sourcedir'] = sys_get_temp_dir();
        $GLOBALS['context'] = [];
        $GLOBALS['txt'] += ['settings' => 'Settings'];
        $GLOBALS['scripturl'] = 'https://example.test/index.php';

        (new SpamJudge())->settings();

        Assert::same($GLOBALS['context']['page_title'], 'Spam Judge');
        Assert::same($GLOBALS['context']['settings_title'], 'Settings');
        Assert::notBlank($GLOBALS['spam_judge_prepared_settings']);
    }

    public function mainUsesLogsSubActionAndBuildsAdminContext(): void
    {
        Environment::reset();
        $GLOBALS['sourcedir'] = sys_get_temp_dir();
        file_put_contents($GLOBALS['sourcedir'] . '/Subs-List.php', '<?php');
        $GLOBALS['context'] = ['admin_menu_name' => 'admin'];
        $GLOBALS['txt'] += [
            'spam_judge_settings_description' => 'Settings description',
            'spam_judge_logs_description' => 'Logs description',
        ];
        $_REQUEST = ['sa' => 'logs'];

        (new SpamJudge())->main();

        Assert::same($GLOBALS['context']['admin']['tab_data']['title'], 'Spam Judge');
        Assert::same($GLOBALS['context']['admin']['tab_data']['tabs']['logs']['description'], 'Logs description');
        Assert::same($GLOBALS['context']['sub_template'], 'show_list');
        Assert::same($GLOBALS['context']['default_list'], 'spam_judge_logs_list');
    }

    public function listMethodsReadRowsAndCountFromDatabase(): void
    {
        Environment::reset();
        $GLOBALS['spam_judge_db_rows'] = [['id_log' => 1], ['id_log' => 2], null];

        $judge = new SpamJudge();
        Assert::same($judge->listGetLogs(0, 20, 'log_time DESC'), [['id_log' => 1], ['id_log' => 2]]);

        $GLOBALS['spam_judge_db_rows'] = [['total' => 3]];
        Assert::same($judge->listGetCount(), 3);
    }

    public function adminAreasRegistersSpamJudgeSection(): void
    {
        Environment::reset();
        $GLOBALS['sourcedir'] = sys_get_temp_dir();
        file_put_contents($GLOBALS['sourcedir'] . '/ManageSettings.php', '<?php');
        $GLOBALS['txt'] += ['settings' => 'Settings'];

        $admin_areas = ['config' => ['areas' => []]];
        (new SpamJudge())->adminAreas($admin_areas);

        Assert::same($admin_areas['config']['areas']['spamjudge']['label'], 'Spam Judge');
        Assert::same($admin_areas['config']['areas']['spamjudge']['icon'], 'security');
        Assert::same($admin_areas['config']['areas']['spamjudge']['subsections']['logs'], ['Logs']);
    }

    public function mainFallsBackToSettingsSubAction(): void
    {
        Environment::reset();
        $GLOBALS['sourcedir'] = sys_get_temp_dir();
        $GLOBALS['context'] = ['admin_menu_name' => 'admin'];
        $GLOBALS['txt'] += [
            'settings' => 'Settings',
            'spam_judge_settings_description' => 'Settings description',
            'spam_judge_logs_description' => 'Logs description',
        ];
        $GLOBALS['scripturl'] = 'https://example.test/index.php';
        $_REQUEST = ['sa' => 'settings'];

        (new SpamJudge())->main();

        Assert::notBlank($GLOBALS['spam_judge_prepared_settings']);
    }

    public function settingsSavesAndRedirectsWhenRequested(): void
    {
        Environment::reset();
        $GLOBALS['sourcedir'] = sys_get_temp_dir();
        $GLOBALS['context'] = [];
        $GLOBALS['txt'] += ['settings' => 'Settings'];
        $GLOBALS['scripturl'] = 'https://example.test/index.php';
        $_GET = ['save' => ''];

        $redirected = false;

        try {
            (new SpamJudge())->settings();
        } catch (\RuntimeException $exception) {
            $redirected = true;
            Assert::same($exception->getMessage(), 'redirect:action=admin;area=spamjudge;sa=settings');
        }

        Assert::same($redirected, true);
        Assert::same($GLOBALS['spam_judge_check_session'], true);
        Assert::notBlank($GLOBALS['spam_judge_saved_settings']);
    }

    public function settingsListsRestrictableMemberGroups(): void
    {
        Environment::reset();
        $GLOBALS['sourcedir'] = sys_get_temp_dir();
        $GLOBALS['context'] = [];
        $GLOBALS['txt'] += ['settings' => 'Settings'];
        $GLOBALS['scripturl'] = 'https://example.test/index.php';
        $GLOBALS['spam_judge_db_rows'] = [['id_group' => 2, 'group_name' => 'Members']];

        $config = (new SpamJudge())->settings(true);

        Assert::same($config[7][2][2], 'Members');
    }
}
