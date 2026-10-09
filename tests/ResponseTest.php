<?php

declare(strict_types=1);

use Bugo\SpamJudge\Integration;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

require_once __DIR__ . '/Environment.php';

#[Test]
#[Covers(Integration::class)]
final class ResponseTest
{
    public function parsesValidSystemOneResponse(): void
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

    public function parsesValidOpenAiResponse(): void
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

    public function parsesOpenAiResponseWithOnlyProbabilitiesList(): void
    {
        Environment::reset();

        $result = $this->parseResponse(json_encode([
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
        ]));

        Assert::same($result, ['ham', ['ham' => 0.95, 'spam' => 0.03, 'review' => 0.02]]);
        Assert::same($GLOBALS['spam_judge_errors'], []);
    }

    #[DataSet(['not-json'], 'invalid JSON')]
    #[DataSet(['5'], 'non-array JSON payload')]
    #[DataSet(['{"answers": []}'], 'missing classification answer')]
    #[DataSet(['{"answers":{"classification":{"type":"choice","choice":"spam","probabilities":{"ham":0,"spam":1}}}}'], 'missing probability')]
    #[DataSet(['{"answers":{"classification":{"type":"choice","choice":"spam","probabilities":{"ham":0,"spam":1.1,"review":0}}}}'], 'probability above one')]
    #[DataSet(['{"answers":[[{"name":"classification","type":"other","choice":"spam","probabilities":[{"value":"spam","probability":1}]}]]}'], 'unsupported answer type (not choice)')]
    #[DataSet(['{"answers":[[{"name":"other","type":"choice","choice":"spam","probabilities":[{"value":"spam","probability":1}]}]]}'], 'classification answer not found in list')]
    #[DataSet(['{"answers":[[{"name":"classification","type":"choice","choice":"spam","probabilities":[{"value":"spam","probability":1.1}]}]]}'], 'OpenAI probability above one')]
    #[DataSet(['{"answers":[[{"name":"classification","type":"choice","choice":"spam","probabilities":[{"probability":1}]}]]}'], 'OpenAI probability missing value')]
    public function rejectsInvalidGatewayResponse(string $response): void
    {
        Environment::reset();

        Assert::null($this->parseResponse($response));
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

    private function parseResponse(string $response): ?array
    {
        $method = new ReflectionMethod(Integration::class, 'parseResponse');
        $method->setAccessible(true);

        return $method->invoke(new Integration(), $response);
    }
}
