<?php

declare(strict_types=1);

use Bugo\SpamJudge\ApiType;
use Bugo\SpamJudge\Integration;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

require_once __DIR__ . '/Environment.php';

#[Test]
#[Covers(Integration::class)]
final class ModerationTest
{
    public function restrictsConfidentSpamRegistrationAndLogsIt(): void
    {
        Environment::reset();

        [$process, $url] = Environment::startGateway('spam');

        $GLOBALS['modSettings'] = [
            'spam_judge_enabled'          => 1,
            'spam_judge_gateway_url'      => $url,
            'spam_judge_api_key'          => 'secret',
            'spam_judge_restricted_group' => 7,
            'spam_judge_spam_threshold'   => 0.95,
        ];

        (new Integration())->onRegister(['username' => 'bot', 'email' => 'bot@example.com'], [], 42);

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
            'spam_judge_enabled'     => 1,
            'spam_judge_gateway_url' => $url,
            'spam_judge_api_key'     => 'secret',
        ];

        (new Integration())->onRegister(['username' => 'member', 'email' => 'member@example.com'], [], 42);

        Environment::stopGateway($process);

        Assert::same($GLOBALS['spam_judge_queries'], []);
        Assert::same($GLOBALS['spam_judge_inserts'][0][3][9], 'none');
    }

    public function logsReviewRegistrationWithoutAutomaticAction(): void
    {
        Environment::reset();

        [$process, $url] = Environment::startGateway('review');

        $GLOBALS['modSettings'] = [
            'spam_judge_enabled'     => 1,
            'spam_judge_gateway_url' => $url,
            'spam_judge_api_key'     => 'secret',
        ];

        (new Integration())->onRegister(['username' => 'new-user', 'email' => 'new@example.com'], [], 42);

        Environment::stopGateway($process);

        Assert::same($GLOBALS['spam_judge_queries'], []);
        Assert::same($GLOBALS['spam_judge_inserts'][0][3][9], 'none');
    }

    public function doesNotRestrictSpamBelowConfiguredConfidence(): void
    {
        Environment::reset();

        [$process, $url] = Environment::startGateway('spam');

        $GLOBALS['modSettings'] = [
            'spam_judge_enabled'        => 1,
            'spam_judge_gateway_url'    => $url,
            'spam_judge_api_key'        => 'secret',
            'spam_judge_spam_threshold' => 0.99,
        ];

        (new Integration())->onRegister(['username' => 'bot', 'email' => 'bot@example.com'], [], 42);

        Environment::stopGateway($process);

        Assert::same($GLOBALS['spam_judge_queries'], []);
        Assert::same($GLOBALS['spam_judge_inserts'][0][3][9], 'none');
    }

    public function unapprovesConfidentSpamFirstPostAndLogsStrippedContent(): void
    {
        Environment::reset();

        [$process, $url] = Environment::startGateway('spam');

        $GLOBALS['modSettings'] = [
            'spam_judge_enabled'          => 1,
            'spam_judge_check_first_post' => 1,
            'spam_judge_post_threshold'   => 1,
            'spam_judge_gateway_url'      => $url,
            'spam_judge_api_key'          => 'secret',
        ];

        $GLOBALS['user_info'] = ['posts' => 1, 'username' => 'bot', 'email' => 'bot@example.com'];

        (new Integration())->onPost(['id' => 99, 'body' => '<b>Buy now</b>'], [], ['id' => 42]);

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
            'spam_judge_enabled'        => 1,
            'spam_judge_gateway_url'    => $url,
            'spam_judge_api_key'        => 'secret',
            'spam_judge_spam_threshold' => 0.95,
        ];

        (new Integration())->onRegister(['username' => 'bot', 'email' => 'bot@example.com'], [], 42);

        Environment::stopGateway($process);

        Assert::same($GLOBALS['spam_judge_queries'], []);
        Assert::same($GLOBALS['spam_judge_inserts'][0][3][9], 'restricted');
    }

    public function failsOpenWhenGatewayConfigurationIsMissing(): void
    {
        Environment::reset();

        $GLOBALS['modSettings'] = ['spam_judge_enabled' => 1];

        (new Integration())->onRegister(['username' => 'user', 'email' => 'user@example.com'], [], 42);

        Assert::same($GLOBALS['spam_judge_inserts'], []);
        Assert::count($GLOBALS['spam_judge_errors'], 1);
    }

    public function failsOpenWhenPayloadCannotBeEncoded(): void
    {
        Environment::reset();

        $GLOBALS['modSettings'] = [
            'spam_judge_gateway_url' => 'http://127.0.0.1:1',
            'spam_judge_api_key'     => 'secret',
            'spam_judge_model'       => "\xB1\x31\x80",
        ];

        Assert::null($this->classify('content'));
        Assert::count($GLOBALS['spam_judge_errors'], 1);
    }

    public function failsOpenWhenGatewayIsUnreachable(): void
    {
        Environment::reset();

        $GLOBALS['modSettings'] = [
            'spam_judge_gateway_url' => 'http://127.0.0.1:1',
            'spam_judge_api_key'     => 'secret',
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
            'spam_judge_api_key'     => 'secret',
        ];

        $result = $this->classify('content');
        Environment::stopGateway($process);

        Assert::null($result);
        Assert::count($GLOBALS['spam_judge_errors'], 1);
    }

    public function buildsSystemOnePayload(): void
    {
        Environment::reset();

        $body = $this->buildPayload(ApiType::SystemOne, 'jev-latest', 'hello');

        Assert::same($body, json_encode([
            'model'     => 'jev-latest',
            'state'     => ['content' => 'hello'],
            'questions' => [
                'classification' => [
                    'type'         => 'choice',
                    'instructions' => 'Classify the supplied forum content for anti-spam moderation. Choose spam only for clearly unsolicited or deceptive promotional content. Choose ham for legitimate user content. Choose review when the content is ambiguous or there is not enough evidence.',
                    'criteria'     => [
                        'ham'    => 'Legitimate forum content, including ordinary discussion, questions, and relevant links.',
                        'spam'   => 'Unsolicited or deceptive promotion, link farming, or automated spam.',
                        'review' => 'Ambiguous content or insufficient evidence to confidently classify as ham or spam.',
                    ],
                ],
            ],
        ]));
    }

    public function buildsOpenAiPayload(): void
    {
        Environment::reset();

        $body = $this->buildPayload(ApiType::OpenAi, 'gpt-6-luna', 'hello');

        Assert::same($body, json_encode([
            'model'     => 'gpt-6-luna',
            'input'     => 'hello',
            'questions' => [
                [
                    'name'         => 'classification',
                    'type'         => 'choice',
                    'instructions' => 'Classify the supplied forum content for anti-spam moderation. Choose spam only for clearly unsolicited or deceptive promotional content. Choose ham for legitimate user content. Choose review when the content is ambiguous or there is not enough evidence.',
                    'choices'      => [
                        ['value' => 'ham', 'description' => 'Legitimate forum content, including ordinary discussion, questions, and relevant links.'],
                        ['value' => 'spam', 'description' => 'Unsolicited or deceptive promotion, link farming, or automated spam.'],
                        ['value' => 'review', 'description' => 'Ambiguous content or insufficient evidence to confidently classify as ham or spam.'],
                    ],
                ],
            ],
        ]));
    }

    #[DataSet(['system_one'], 'System One default model')]
    #[DataSet(['openai'], 'OpenAI default model')]
    public function selectsDefaultModelBasedOnApiType(string $apiTypeValue): void
    {
        Environment::reset();

        [$process, $url] = Environment::startGateway('ham', $apiTypeValue);

        $GLOBALS['modSettings'] = [
            'spam_judge_enabled'     => 1,
            'spam_judge_api_type'    => $apiTypeValue,
            'spam_judge_gateway_url' => $url,
            'spam_judge_api_key'     => 'secret',
        ];

        (new Integration())->onRegister(['username' => 'bot', 'email' => 'bot@example.com'], [], 42);

        $payload = json_decode(Environment::receivedRequest(), true);
        Environment::stopGateway($process);

        $expectedModel = $apiTypeValue === 'openai' ? 'gpt-6-luna' : 'jev-latest';

        Assert::same($payload['model'], $expectedModel);
        Assert::same($GLOBALS['spam_judge_errors'], []);
    }

    public function parsesSystemOneResponse(): void
    {
        Environment::reset();

        $result = $this->parseResponse(json_encode([
            'answers' => [
                'classification' => [
                    'type'          => 'choice',
                    'choice'        => 'spam',
                    'probabilities' => ['ham' => '0.01', 'spam' => 1, 'review' => 0.0],
                ],
            ],
        ]));

        Assert::same($result, ['spam', ['ham' => 0.01, 'spam' => 1.0, 'review' => 0.0]]);
        Assert::same($GLOBALS['spam_judge_errors'], []);
    }

    public function parsesOpenAiResponse(): void
    {
        Environment::reset();

        $result = $this->parseResponse(json_encode([
            'answers' => [
                [
                    'name'          => 'classification',
                    'type'          => 'choice',
                    'choice'        => 'spam',
                    'probability'   => 0.98,
                    'probabilities' => [
                        ['value' => 'ham', 'probability' => 0.01],
                        ['value' => 'spam', 'probability' => 0.98],
                        ['value' => 'review', 'probability' => 0.01],
                    ],
                ],
            ],
        ]));

        Assert::same($result, ['spam', ['ham' => 0.01, 'spam' => 0.98, 'review' => 0.01]]);
        Assert::same($GLOBALS['spam_judge_errors'], []);
    }

    private function classify(string $content): ?array
    {
        $method = new ReflectionMethod(Integration::class, 'classify');
        $method->setAccessible(true);

        return $method->invoke(new Integration(), $content);
    }

    private function buildPayload(ApiType $apiType, string $model, string $content): string
    {
        $method = new ReflectionMethod(Integration::class, 'buildPayload');
        $method->setAccessible(true);

        return $method->invoke(new Integration(), $apiType, $model, $content);
    }

    private function parseResponse(string $response): ?array
    {
        $method = new ReflectionMethod(Integration::class, 'parseResponse');
        $method->setAccessible(true);

        return $method->invoke(new Integration(), $response);
    }
}
