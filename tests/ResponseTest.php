<?php

declare(strict_types=1);

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

require_once __DIR__ . '/Environment.php';

#[Test]
#[Covers(SpamJudge::class)]
final class ResponseTest
{
    public function parsesValidGatewayResponse(): void
    {
        Environment::reset();

        $result = $this->parseResponse([
            'answers' => [
                'classification' => [
                    'type' => 'choice',
                    'choice' => 'spam',
                    'probabilities' => ['ham' => '0.01', 'spam' => 1, 'review' => 0.0],
                ],
            ],
        ]);

        Assert::same($result, ['spam', ['ham' => 0.01, 'spam' => 1.0, 'review' => 0.0]]);
        Assert::same($GLOBALS['spam_judge_errors'], []);
    }

    #[DataSet(['not-json'], 'invalid JSON')]
    #[DataSet(['5'], 'non-array JSON payload')]
    #[DataSet(['{"answers": []}'], 'missing classification answer')]
    #[DataSet(['{"answers":{"classification":{"type":"choice","choice":"spam","probabilities":{"ham":0,"spam":1}}}}'], 'missing probability')]
    #[DataSet(['{"answers":{"classification":{"type":"choice","choice":"spam","probabilities":{"ham":0,"spam":1.1,"review":0}}}}'], 'probability above one')]
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

        $method = new ReflectionMethod(SpamJudge::class, 'isSpamConfident');
        $method->setAccessible(true);

        Assert::same($method->invoke(new SpamJudge(), ['spam' => $confidence]), $expected);
    }

    private function parseResponse(array|string $response): ?array
    {
        $method = new ReflectionMethod(SpamJudge::class, 'parseResponse');
        $method->setAccessible(true);

        return $method->invoke(new SpamJudge(), is_array($response) ? json_encode($response) : $response);
    }
}
