<?php

declare(strict_types=1);

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

require_once __DIR__ . '/Environment.php';

#[Test]
#[Covers(SpamJudge::class)]
final class ModerationTest
{
    public function restrictsConfidentSpamRegistrationAndLogsIt(): void
    {
        Environment::reset();
        [$process, $url] = Environment::startGateway('spam');
        $GLOBALS['modSettings'] = [
            'spam_judge_enabled' => 1,
            'spam_judge_gateway_url' => $url,
            'spam_judge_api_key' => 'secret',
            'spam_judge_restricted_group' => 7,
            'spam_judge_spam_threshold' => 0.95,
        ];

        (new SpamJudge())->onRegister(['username' => 'bot', 'email' => 'bot@example.com'], [], 42);
        Environment::stopGateway($process);

        Assert::same($GLOBALS['spam_judge_queries'][0]['params'], ['group' => 7, 'member' => 42]);
        Assert::same($GLOBALS['spam_judge_inserts'][0][3][1], 'registration');
        Assert::same($GLOBALS['spam_judge_inserts'][0][3][9], 'restricted');
    }

    public function logsHamRegistrationWithoutRestrictingIt(): void
    {
        Environment::reset();
        [$process, $url] = Environment::startGateway('ham');
        $GLOBALS['modSettings'] = [
            'spam_judge_enabled' => 1,
            'spam_judge_gateway_url' => $url,
            'spam_judge_api_key' => 'secret',
        ];

        (new SpamJudge())->onRegister(['username' => 'member', 'email' => 'member@example.com'], [], 42);
        Environment::stopGateway($process);

        Assert::same($GLOBALS['spam_judge_queries'], []);
        Assert::same($GLOBALS['spam_judge_inserts'][0][3][9], 'none');
    }

    public function logsReviewRegistrationWithoutAutomaticAction(): void
    {
        Environment::reset();
        [$process, $url] = Environment::startGateway('review');
        $GLOBALS['modSettings'] = [
            'spam_judge_enabled' => 1,
            'spam_judge_gateway_url' => $url,
            'spam_judge_api_key' => 'secret',
        ];

        (new SpamJudge())->onRegister(['username' => 'new-user', 'email' => 'new@example.com'], [], 42);
        Environment::stopGateway($process);

        Assert::same($GLOBALS['spam_judge_queries'], []);
        Assert::same($GLOBALS['spam_judge_inserts'][0][3][9], 'none');
    }

    public function doesNotRestrictSpamBelowConfiguredConfidence(): void
    {
        Environment::reset();
        [$process, $url] = Environment::startGateway('spam');
        $GLOBALS['modSettings'] = [
            'spam_judge_enabled' => 1,
            'spam_judge_gateway_url' => $url,
            'spam_judge_api_key' => 'secret',
            'spam_judge_spam_threshold' => 0.99,
        ];

        (new SpamJudge())->onRegister(['username' => 'bot', 'email' => 'bot@example.com'], [], 42);
        Environment::stopGateway($process);

        Assert::same($GLOBALS['spam_judge_queries'], []);
        Assert::same($GLOBALS['spam_judge_inserts'][0][3][9], 'none');
    }

    public function unapprovesConfidentSpamFirstPostAndLogsStrippedContent(): void
    {
        Environment::reset();
        [$process, $url] = Environment::startGateway('spam');
        $GLOBALS['modSettings'] = [
            'spam_judge_enabled' => 1,
            'spam_judge_check_first_post' => 1,
            'spam_judge_post_threshold' => 1,
            'spam_judge_gateway_url' => $url,
            'spam_judge_api_key' => 'secret',
        ];
        $GLOBALS['user_info'] = ['posts' => 1, 'username' => 'bot', 'email' => 'bot@example.com'];

        (new SpamJudge())->onPost(['id' => 99, 'body' => '<b>Buy now</b>'], [], ['id' => 42]);
        Environment::stopGateway($process);

        Assert::same($GLOBALS['spam_judge_queries'][0]['params'], ['approved' => 0, 'msg' => 99]);
        Assert::same($GLOBALS['spam_judge_inserts'][0][3][1], 'post');
        Assert::same($GLOBALS['spam_judge_inserts'][0][3][6], 'Buy now');
        Assert::same($GLOBALS['spam_judge_inserts'][0][3][9], 'unapproved');
    }

    public function logsConfidentSpamWithoutRestrictingWhenNoGroupConfigured(): void
    {
        Environment::reset();
        [$process, $url] = Environment::startGateway('spam');
        $GLOBALS['modSettings'] = [
            'spam_judge_enabled' => 1,
            'spam_judge_gateway_url' => $url,
            'spam_judge_api_key' => 'secret',
            'spam_judge_spam_threshold' => 0.95,
        ];

        (new SpamJudge())->onRegister(['username' => 'bot', 'email' => 'bot@example.com'], [], 42);
        Environment::stopGateway($process);

        Assert::same($GLOBALS['spam_judge_queries'], []);
        Assert::same($GLOBALS['spam_judge_inserts'][0][3][9], 'restricted');
    }

    public function failsOpenWhenGatewayConfigurationIsMissing(): void
    {
        Environment::reset();
        $GLOBALS['modSettings'] = ['spam_judge_enabled' => 1];

        (new SpamJudge())->onRegister(['username' => 'user', 'email' => 'user@example.com'], [], 42);

        Assert::same($GLOBALS['spam_judge_inserts'], []);
        Assert::count($GLOBALS['spam_judge_errors'], 1);
    }

    public function failsOpenWhenPayloadCannotBeEncoded(): void
    {
        Environment::reset();
        $GLOBALS['modSettings'] = [
            'spam_judge_gateway_url' => 'http://127.0.0.1:1',
            'spam_judge_api_key' => 'secret',
            'spam_judge_model' => "\xB1\x31\x80",
        ];

        Assert::null($this->classify('content'));
        Assert::count($GLOBALS['spam_judge_errors'], 1);
    }

    public function failsOpenWhenGatewayIsUnreachable(): void
    {
        Environment::reset();
        $GLOBALS['modSettings'] = [
            'spam_judge_gateway_url' => 'http://127.0.0.1:1',
            'spam_judge_api_key' => 'secret',
        ];

        Assert::null($this->classify('content'));
        Assert::count($GLOBALS['spam_judge_errors'], 1);
    }

    public function failsOpenOnNonSuccessfulHttpStatus(): void
    {
        Environment::reset();
        [$process, $url] = Environment::startFailingGateway();
        $GLOBALS['modSettings'] = [
            'spam_judge_gateway_url' => $url,
            'spam_judge_api_key' => 'secret',
        ];

        $result = $this->classify('content');
        Environment::stopGateway($process);

        Assert::null($result);
        Assert::count($GLOBALS['spam_judge_errors'], 1);
    }

    private function classify(string $content): ?array
    {
        $method = new ReflectionMethod(SpamJudge::class, 'classify');
        $method->setAccessible(true);

        return $method->invoke(new SpamJudge(), $content);
    }
}
