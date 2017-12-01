<?php declare(strict_types=1);

namespace RPQ\Queue\Process\Handler;

use Monolog\Logger;
use RPQ\Client;
use RPQ\Client\Exception\JobNotFoundException;

final class JobHandler
{
    /**
     * @var Client $client
     */
    private $client;

    /**
     * @var Logger $this->logger
     */
    private $logger;

    /**
     * @var string $queue
     */
    private $queue;

    /**
     * Constructor
     *
     * @param Client $client
     * @param Logger $logger
     * @param string $queue
     */
    public function __construct(Client $client, Logger $logger, $queue)
    {
        $this->client = $client;
        $this->logger = $logger;
        $this->queue = $queue;
    }

    /**
     * Handles process end, and requeuing if necessary
     *
     * @param string $id
     * @param int $pid
     * @param int $code
     * @param bool $forceRetry
     * @return void
     */
    public function exit($id, $pid, $code, $forceRetry = false)
    {
        $this->logger->info('Job ended', [
            'exitCode' => $code,
            'pid' => $pid,
            'jobId' => $id,
            'queue' => $this->queue
        ]);

        $hash = explode(':', $id);
        $jobId = $hash[count($hash) - 1];
        try {
            $jobDetails = $this->client->getJobById($jobId);
        } catch (JobNotFoundException $e) {
            $this->logger->info('Unable to process job exit code or retry status. Job data unavailable', [
                'exitCode' => $code,
                'pid' => $pid,
                'jobId' => $id,
                'queue' => $this->queue
            ]);
            return true;
        }

        // If the job ended successfully, remove the data from redis
        if ($code === 0) {
            $this->logger->info('Job succeeded and is now complete', [
                'exitCode' => $code,
                'pid' => $pid,
                'jobId' => $id,
                'queue' => $this->queue
            ]);

            return $this->client->getRedis()->hdel($id);
        } else {

            $retryArgs = \explode(':', $jobDetails['retry']);
            $retry = $retryArgs[0] === 'boolean' ? (bool)$retryArgs[1] : (int)$retryArgs[1];

            // If force retry was specified, force this job to retry indefinitely
            if ($forceRetry === true) {
                $retry = true;
            }

            if ($retry === true || $retry > 0) {
                $this->logger->info('Rescheduling job', [
                    'exitCode' => $code,
                    'pid' => $pid,
                    'jobId' => $id,
                    'queue' => $this->queue
                ]);

                // If a retry is specified, repush the job back onto the queue with the same Job ID
                return $this->client->push(
                    $jobDetails['workerClass'],
                    $jobDetails['args'],
                    \gettype($retry) === 'boolean' ? (bool)$retry : (int)($retry - 1),
                    (float)$jobDetails['priority'],
                    $this->queue,
                    null, 
                    $jobId
                );
            } else {
                $this->logger->info('Job failed', [
                    'exitCode' => $code,
                    'pid' => $pid,
                    'jobId' => $id,
                    'queue' => $this->queue
                ]);

                return $this->client->getRedis()->hdel($id);
            }
        }

        return;
    }
}