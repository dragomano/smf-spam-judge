<?php

declare(strict_types=1);

namespace Tests;

use Bugo\SpamJudge\Classifier;
use Bugo\SpamJudge\Http\HttpClient;
use Bugo\SpamJudge\Http\InvalidJsonException;
use Bugo\SpamJudge\Integration;
use Bugo\SpamJudge\Strategy\StrategyFactory;
use Bugo\SpamJudge\Strategy\AbstractStrategy;
use Bugo\SpamJudge\Strategy\InvalidAnswerException;
use Bugo\SpamJudge\Strategy\InvalidProbabilitiesException;
use Bugo\SpamJudge\Strategy\InvalidResponseException;
use Bugo\SpamJudge\Strategy\OpenAiStrategy;
use Bugo\SpamJudge\Strategy\Result;
use Bugo\SpamJudge\Strategy\SystemOneStrategy;
use ReflectionMethod;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(Integration::class)]
#[Covers(Classifier::class)]
#[Covers(StrategyFactory::class)]
#[Covers(SystemOneStrategy::class)]
#[Covers(OpenAiStrategy::class)]
#[Covers(AbstractStrategy::class)]
#[Covers(InvalidJsonException::class)]
#[Covers(Result::class)]
#[Covers(InvalidResponseException::class)]
#[Covers(InvalidAnswerException::class)]
#[Covers(InvalidProbabilitiesException::class)]
final class ResponseTest
{
    public function parsesValidSystemOneResponse(): void
    {
        Environment::reset();

        $result = $this->extract(SystemOneStrategy::class, [
            'answers' => [
                'classification' => [
                    'type'          => 'choice',
                    'choice'        => 'spam',
                    'probabilities' => ['ham' => '0.01', 'spam' => 1, 'review' => 0.0],
                ],
            ],
        ]);

        Assert::same($result?->verdict, 'spam');
        Assert::same($result?->probabilities, ['ham' => 0.01, 'spam' => 1.0, 'review' => 0.0]);
        Assert::same($GLOBALS['spam_judge_errors'], []);
    }

    public function parsesValidOpenAiResponse(): void
    {
        Environment::reset();

        $result = $this->extract(OpenAiStrategy::class, [
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
        ]);

        Assert::same($result?->verdict, 'spam');
        Assert::same($result?->probabilities, ['ham' => 0.01, 'spam' => 0.98, 'review' => 0.01]);
        Assert::same($GLOBALS['spam_judge_errors'], []);
    }

    public function parsesOpenAiResponseWithOnlyProbabilitiesList(): void
    {
        Environment::reset();

        $result = $this->extract(OpenAiStrategy::class, [
            'answers' => [
                [
                    'name'          => 'classification',
                    'type'          => 'choice',
                    'choice'        => 'ham',
                    'probabilities' => [
                        ['value' => 'ham', 'probability' => 0.95],
                        ['value' => 'spam', 'probability' => 0.03],
                        ['value' => 'review', 'probability' => 0.02],
                    ],
                ],
            ],
        ]);

        Assert::same($result?->verdict, 'ham');
        Assert::same($result?->probabilities, ['ham' => 0.95, 'spam' => 0.03, 'review' => 0.02]);
        Assert::same($GLOBALS['spam_judge_errors'], []);
    }

    #[DataSet(['system_one', '{"answers": "x"}', InvalidResponseException::class], 'answers is not an array')]
    #[DataSet(['system_one', '{"answers": []}', InvalidAnswerException::class], 'classification answer not found')]
    #[DataSet(['system_one', '{"answers":{"classification":{"type":"choice","choice":"spam","probabilities":{"ham":0,"spam":1}}}}', InvalidProbabilitiesException::class], 'missing probability')]
    #[DataSet(['system_one', '{"answers":{"classification":{"type":"choice","choice":"spam","probabilities":{"ham":0,"spam":1.1,"review":0}}}}', InvalidProbabilitiesException::class], 'probability above one')]
    #[DataSet(['openai', '{"answers": "x"}', InvalidResponseException::class], 'answers is not an array')]
    #[DataSet(['openai', '{"answers":[[{"name":"classification","type":"other","choice":"spam","probabilities":[{"value":"spam","probability":1}]}]]}', InvalidAnswerException::class], 'unsupported answer type (not choice)')]
    #[DataSet(['openai', '{"answers":[[{"name":"other","type":"choice","choice":"spam","probabilities":[{"value":"spam","probability":1}]}]]}', InvalidAnswerException::class], 'classification answer not found in list')]
    #[DataSet(['openai', '{"answers":[{"name":"classification","type":"choice","choice":"spam","probabilities":[{"value":"spam","probability":1.1}]}]}', InvalidProbabilitiesException::class], 'OpenAI probability above one')]
    #[DataSet(['openai', '{"answers":[{"name":"classification","type":"choice","choice":"spam","probabilities":[{"probability":1}]}]}', InvalidProbabilitiesException::class], 'OpenAI probability missing value')]
    public function rejectsInvalidGatewayResponse(string $apiType, string $json, string $expected): void
    {
        Environment::reset();

        $strategy = $apiType === 'openai' ? OpenAiStrategy::class : SystemOneStrategy::class;

        $this->extractAndExpect($strategy, json_decode($json, true), $expected);
    }

    #[DataSet(['not-json'], 'invalid JSON')]
    #[DataSet(['5'], 'non-array JSON payload')]
    #[DataSet(['{"answers": "x"}'], 'answers is not an array')]
    #[DataSet(['{"answers": []}'], 'classification answer not found')]
    #[DataSet(['{"answers":{"classification":{"type":"other","choice":"spam","probabilities":{"ham":0,"spam":1,"review":0}}}}'], 'unsupported answer type (not choice)')]
    #[DataSet(['{"answers":{"classification":{"type":"choice","choice":"spam","probabilities":{"ham":0,"spam":1}}}}'], 'missing probability')]
    public function failsOpenWhenGatewayReturnsInvalidJsonBody(string $body): void
    {
        Environment::reset();

        [$process, $url] = Environment::startRawGateway($body);

        $GLOBALS['modSettings'] = [
            'spam_judge_gateway_url' => $url,
            'spam_judge_api_key'     => 'secret',
        ];

        $result = $this->classify('content');
        Environment::stopGateway($process);

        Assert::null($result);
        Assert::count($GLOBALS['spam_judge_errors'], 1);
    }

    #[DataSet([0.95, true], 'threshold itself is accepted')]
    #[DataSet([0.949, false], 'value below threshold is rejected')]
    public function checksSpamConfidence(float $confidence, bool $expected): void
    {
        Environment::reset();
        $GLOBALS['modSettings'] = ['spam_judge_spam_threshold' => 0.95];

        $method = new ReflectionMethod(Integration::class, 'isSpamConfident');
        $method->setAccessible(true);

        Assert::same($method->invoke(new Integration(), ['spam' => $confidence]), $expected);
    }

    private function extract(string $strategy, array $data): ?Result
    {
        $method = new ReflectionMethod($strategy, 'extractResult');
        $method->setAccessible(true);

        $instance = new $strategy(new HttpClient(), 'http://127.0.0.1:1', 'secret', 'test-model');

        return $method->invoke($instance, $data);
    }

    private function extractAndExpect(string $strategy, mixed $data, string $expected): void
    {
        try {
            $this->extract($strategy, $data);

            Assert::fail('Expected ' . $expected);
        } catch (InvalidResponseException $e) {
            Assert::same($e::class, $expected);
        }
    }

    private function classify(string $content): ?Result
    {
        $method = new ReflectionMethod(Integration::class, 'classify');
        $method->setAccessible(true);

        return $method->invoke(new Integration(), $content);
    }
}
