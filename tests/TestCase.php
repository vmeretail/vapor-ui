<?php

namespace Tests;

use Aws\CloudWatch\CloudWatchClient;
use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\Result;
use Aws\Sqs\SqsClient;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\File;
use Laravel\VaporUi\VaporUiServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * Setup the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareManifest();
        $this->mockAwsClients();

        $this->artisan('vapor-ui:publish', ['--force' => true])->run();
    }

    /**
     * Get package providers.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array
     */
    protected function getPackageProviders($app)
    {
        return [VaporUiServiceProvider::class];
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        Gate::define('viewVaporUI', function ($user = null) {
            return true;
        });

        parent::getEnvironmentSetUp($app);
    }

    /**
     * Prepare the Vapor manifest fixture.
     *
     * @return void
     */
    protected function prepareManifest()
    {
        File::ensureDirectoryExists(base_path('deploy/production'));

        File::put(base_path('vapor.yml'), sprintf(
            "environments:\n  %s:\n    queues:\n      - %s/%s\n",
            $_ENV['VAPOR_ENVIRONMENT'],
            $_ENV['SQS_PREFIX'],
            $_ENV['VAPOR_PROJECT'].'-'.$_ENV['VAPOR_ENVIRONMENT']
        ));
    }

    /**
     * Mock AWS clients used by integration tests.
     *
     * @return void
     */
    protected function mockAwsClients()
    {
        $this->mockCloudWatchLogsClient();
        $this->mockCloudWatchClient();
        $this->mockSqsClient();
    }

    /**
     * Mock the CloudWatch Logs client.
     *
     * @return void
     */
    protected function mockCloudWatchLogsClient()
    {
        $client = \Mockery::mock(CloudWatchLogsClient::class);
        $client->shouldReceive('filterLogEvents')->andReturnUsing(function ($payload) {
            if (str_contains($payload['filterPattern'] ?? '', 'This')
                || ($payload['startTime'] ?? 0) > now()->timestamp * 1000) {
                return new Result(['events' => []]);
            }

            return new Result([
                'events' => $this->logEvents($payload['nextToken'] ?? null),
                'nextToken' => isset($payload['nextToken']) ? null : 'next-page',
            ]);
        });

        $this->app->instance(CloudWatchLogsClient::class, $client);
    }

    /**
     * Mock the CloudWatch metrics client.
     *
     * @return void
     */
    protected function mockCloudWatchClient()
    {
        $client = \Mockery::mock(CloudWatchClient::class);
        $client->shouldReceive('getMetricStatistics')->andReturnUsing(function () {
            return new Result([
                'Datapoints' => [
                    ['Timestamp' => now(), 'Sum' => 5],
                ],
            ]);
        });

        $this->app->instance(CloudWatchClient::class, $client);
    }

    /**
     * Mock the SQS client.
     *
     * @return void
     */
    protected function mockSqsClient()
    {
        $client = \Mockery::mock(SqsClient::class);
        $client->shouldReceive('getQueueAttributes')->andReturn(new Result([
            'Attributes' => [
                'ApproximateNumberOfMessages' => 1,
                'ApproximateNumberOfMessagesNotVisible' => 2,
                'ApproximateNumberOfMessagesDelayed' => 3,
            ],
        ]));

        $this->app->instance(SqsClient::class, $client);
    }

    /**
     * Build CloudWatch log events.
     *
     * @param  string|null  $cursor
     * @return array
     */
    protected function logEvents($cursor = null)
    {
        $offset = $cursor ? 50 : 0;

        return collect(range(1, 50))->map(function ($index) use ($offset) {
            $id = (string) ($offset + $index);

            return [
                'logStreamName' => '2026/05/21/[$LATEST]'.$id,
                'timestamp' => now()->timestamp * 1000,
                'message' => json_encode([
                    'message' => 'Test log '.$id,
                    'level_name' => 'INFO',
                    'context' => [
                        'aws_request_id' => 'request-'.$id,
                    ],
                ]),
                'ingestionTime' => now()->timestamp * 1000,
                'eventId' => $id,
            ];
        })->all();
    }
}
