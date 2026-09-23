<?php

declare(strict_types=1);

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

require_once __DIR__ . '/Environment.php';

#[Test]
#[Covers(SpamJudge::class)]
final class HooksTest
{
    public function registersHooks(): void
    {
        Environment::reset();

        (new SpamJudge())->hooks();

        Assert::same($GLOBALS['spam_judge_hooks'], [
            ['integrate_post_register', SpamJudge::class . '::onRegister#', false],
            ['integrate_after_create_post', SpamJudge::class . '::onPost#', false],
            ['integrate_admin_areas', SpamJudge::class . '::adminAreas#', false],
        ]);
    }

    public function skipsRegistrationWhenDisabled(): void
    {
        Environment::reset();
        $GLOBALS['modSettings'] = ['spam_judge_enabled' => 0];

        (new SpamJudge())->onRegister(['username' => 'bot', 'email' => 'bot@example.com'], [], 42);

        Assert::same($GLOBALS['spam_judge_inserts'], []);
        Assert::same($GLOBALS['spam_judge_errors'], []);
    }

    public function skipsRegistrationForModerators(): void
    {
        Environment::reset();
        $GLOBALS['modSettings'] = ['spam_judge_enabled' => 1];
        $GLOBALS['user_info'] = ['is_mod' => true];

        (new SpamJudge())->onRegister(['username' => 'bot', 'email' => 'bot@example.com'], [], 42);

        Assert::same($GLOBALS['spam_judge_inserts'], []);
    }

    public function skipsEmptyRegistrationData(): void
    {
        Environment::reset();
        $GLOBALS['modSettings'] = ['spam_judge_enabled' => 1];

        (new SpamJudge())->onRegister([], [], 42);

        Assert::same($GLOBALS['spam_judge_inserts'], []);
    }

    public function skipsRegistrationWithoutMemberID(): void
    {
        Environment::reset();
        $GLOBALS['modSettings'] = ['spam_judge_enabled' => 1];

        (new SpamJudge())->onRegister(['username' => 'bot', 'email' => 'bot@example.com'], [], 0);

        Assert::same($GLOBALS['spam_judge_inserts'], []);
        Assert::same($GLOBALS['spam_judge_errors'], []);
    }

    public function skipsPostWhenFirstPostCheckDisabled(): void
    {
        Environment::reset();
        $GLOBALS['modSettings'] = ['spam_judge_enabled' => 1];

        (new SpamJudge())->onPost(['body' => 'message'], [], ['id' => 42]);

        Assert::same($GLOBALS['spam_judge_inserts'], []);
        Assert::same($GLOBALS['spam_judge_errors'], []);
    }

    public function skipsPostWithEmptyContent(): void
    {
        Environment::reset();
        $GLOBALS['modSettings'] = [
            'spam_judge_enabled' => 1,
            'spam_judge_check_first_post' => 1,
            'spam_judge_post_threshold' => 1,
        ];
        $GLOBALS['user_info'] = ['posts' => 0];

        (new SpamJudge())->onPost(['body' => ''], [], ['id' => 42]);

        Assert::same($GLOBALS['spam_judge_inserts'], []);
        Assert::same($GLOBALS['spam_judge_errors'], []);
    }

    public function skipsPostWhenClassificationFails(): void
    {
        Environment::reset();
        $GLOBALS['modSettings'] = [
            'spam_judge_enabled' => 1,
            'spam_judge_check_first_post' => 1,
            'spam_judge_post_threshold' => 1,
        ];
        $GLOBALS['user_info'] = ['posts' => 0];

        (new SpamJudge())->onPost(['body' => 'message'], [], ['id' => 42]);

        Assert::same($GLOBALS['spam_judge_inserts'], []);
        Assert::count($GLOBALS['spam_judge_errors'], 1);
    }

    public function skipsPostWhenMemberIsPastThreshold(): void
    {
        Environment::reset();
        $GLOBALS['modSettings'] = [
            'spam_judge_enabled' => 1,
            'spam_judge_check_first_post' => 1,
            'spam_judge_post_threshold' => 3,
        ];
        $GLOBALS['user_info'] = ['posts' => 4];

        (new SpamJudge())->onPost(['body' => 'message'], [], ['id' => 42]);

        Assert::same($GLOBALS['spam_judge_inserts'], []);
    }
}
