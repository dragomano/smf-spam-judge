<?php

declare(strict_types=1);

namespace Tests;

use Bugo\SpamJudge\ApiType;
use Bugo\SpamJudge\Classifier;
use Bugo\SpamJudge\ErrorLogger;
use Bugo\SpamJudge\Http\CurlInitException;
use Bugo\SpamJudge\Http\HttpClient;
use Bugo\SpamJudge\Http\HttpClientException;
use Bugo\SpamJudge\Http\HttpStatusException;
use Bugo\SpamJudge\Http\InvalidJsonException;
use Bugo\SpamJudge\Integration;
use Bugo\SpamJudge\Strategy\AbstractStrategy;
use Bugo\SpamJudge\Strategy\OpenAiStrategy;
use Bugo\SpamJudge\Strategy\Result;
use Bugo\SpamJudge\Strategy\StrategyFactory;
use Bugo\SpamJudge\Strategy\SystemOneStrategy;
use ReflectionMethod;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(Integration::class)]
#[Covers(Classifier::class)]
#[Covers(ErrorLogger::class)]
#[Covers(StrategyFactory::class)]
#[Covers(OpenAiStrategy::class)]
#[Covers(SystemOneStrategy::class)]
#[Covers(AbstractStrategy::class)]
#[Covers(HttpClient::class)]
#[Covers(HttpClientException::class)]
#[Covers(CurlInitException::class)]
#[Covers(HttpStatusException::class)]
#[Covers(InvalidJsonException::class)]
#[Covers(Result::class)]
final class ModerationTest
{
    public function logsErrorsWithModuleNamePrefix(): void
    {
        Environment::reset();

        (new ErrorLogger('Spam Judge'))('missing configuration');

        Assert::same($GLOBALS['spam_judge_errors'], ['Spam Judge: missing configuration']);
    }

    public function createsStrategyBasedOnApiType(): void
    {
        Environment::reset();

        $factory = new StrategyFactory();

        Assert::instanceOf(
            $factory->create(ApiType::SystemOne, 'http://127.0.0.1:1', 'secret', 'jev-latest'),
            SystemOneStrategy::class,
        );
        Assert::instanceOf(
            $factory->create(ApiType::OpenAi, 'http://127.0.0.1:1', 'secret', 'gpt-6-luna'),
            OpenAiStrategy::class,
        );
    }

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

        $body = $this->buildBody(ApiType::SystemOne, 'jev-latest', 'hello');

        Assert::same($body, [
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
        ]);
    }

    public function buildsOpenAiPayload(): void
    {
        Environment::reset();

        $body = $this->buildBody(ApiType::OpenAi, 'gpt-6-luna', 'hello');

        Assert::same($body, [
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
        ]);
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

    private function classify(string $content): ?Result
    {
        $method = new ReflectionMethod(Integration::class, 'classify');
        $method->setAccessible(true);

        return $method->invoke(new Integration(), $content);
    }

    private function buildBody(ApiType $apiType, string $model, string $content): array
    {
        $strategy = $apiType === ApiType::OpenAi
            ? new OpenAiStrategy(new HttpClient(), 'http://127.0.0.1:1', 'secret', $model)
            : new SystemOneStrategy(new HttpClient(), 'http://127.0.0.1:1', 'secret', $model);

        $method = new ReflectionMethod($strategy, 'buildBody');
        $method->setAccessible(true);

        return $method->invoke($strategy, $content);
    }
}
