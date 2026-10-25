<?php

declare(strict_types=1);

/**
 * Read-only prototype of a 1C exchange queue monitor.
 *
 * Usage:
 * php prototype/exchange-sentinel.php prototype/sample_queue.json \
 *   --now=2026-10-22T11:05:00+03:00
 *
 * Exit codes: 0 = OK, 1 = WARNING, 2 = CRITICAL, 3 = invalid input.
 */

const EXIT_OK = 0;
const EXIT_WARNING = 1;
const EXIT_CRITICAL = 2;
const EXIT_INVALID_INPUT = 3;

/** @return array{file:string, now:?string, pendingAgeMinutes:int, successSilenceMinutes:int, maxAttempts:int} */
function options(array $argv): array
{
    $file = $argv[1] ?? '';
    $result = [
        'file' => $file,
        'now' => null,
        'pendingAgeMinutes' => 10,
        'successSilenceMinutes' => 15,
        'maxAttempts' => 5,
    ];
    foreach (array_slice($argv, 2) as $argument) {
        if (preg_match('/^--now=(.+)$/', $argument, $matches)) $result['now'] = $matches[1];
        if (preg_match('/^--pending-age-minutes=(\d+)$/', $argument, $matches)) $result['pendingAgeMinutes'] = (int) $matches[1];
        if (preg_match('/^--success-silence-minutes=(\d+)$/', $argument, $matches)) $result['successSilenceMinutes'] = (int) $matches[1];
        if (preg_match('/^--max-attempts=(\d+)$/', $argument, $matches)) $result['maxAttempts'] = (int) $matches[1];
    }
    return $result;
}

/** @return array{0:int, 1:array<string, mixed>} */
function invalid(string $message): array
{
    return [EXIT_INVALID_INPUT, ['status' => 'INVALID_INPUT', 'message' => $message]];
}

$options = options($argv);
if ($options['file'] === '' || !is_file($options['file'])) {
    [$exitCode, $report] = invalid('Provide an existing queue JSON file as the first argument.');
} else {
    try {
        $payload = json_decode((string) file_get_contents($options['file']), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || !is_array($payload['jobs'] ?? null) || !is_string($payload['last_success_at'] ?? null)) {
            throw new InvalidArgumentException('Required keys: last_success_at and jobs.');
        }
        $now = new DateTimeImmutable($options['now'] ?? 'now');
        $lastSuccess = new DateTimeImmutable($payload['last_success_at']);
        $pending = array_values(array_filter($payload['jobs'], static fn(mixed $job): bool =>
            is_array($job) && in_array($job['status'] ?? '', ['pending', 'retry'], true)
        ));
        usort($pending, static fn(array $left, array $right): int => strcmp((string) ($left['created_at'] ?? ''), (string) ($right['created_at'] ?? '')));

        $alerts = [];
        $silenceMinutes = max(0, intdiv($now->getTimestamp() - $lastSuccess->getTimestamp(), 60));
        if ($silenceMinutes > $options['successSilenceMinutes']) {
            $alerts[] = [
                'level' => 'CRITICAL',
                'rule' => 'success_silence',
                'message' => "No successful 1C exchange for {$silenceMinutes} minutes.",
            ];
        }

        $oldest = $pending[0] ?? null;
        if ($oldest !== null) {
            $createdAt = new DateTimeImmutable((string) ($oldest['created_at'] ?? ''));
            $ageMinutes = max(0, intdiv($now->getTimestamp() - $createdAt->getTimestamp(), 60));
            if ($ageMinutes > $options['pendingAgeMinutes']) {
                $alerts[] = [
                    'level' => 'CRITICAL',
                    'rule' => 'old_pending_job',
                    'message' => "Oldest pending job {$oldest['order_no']} is {$ageMinutes} minutes old.",
                ];
            }
            if ((int) ($oldest['attempts'] ?? 0) > $options['maxAttempts']) {
                $alerts[] = [
                    'level' => 'CRITICAL',
                    'rule' => 'retry_loop',
                    'message' => "Job {$oldest['order_no']} has {$oldest['attempts']} attempts: " . ($oldest['last_error'] ?? 'no error text'),
                ];
            }
        }

        $level = $alerts === [] ? 'OK' : 'CRITICAL';
        $exitCode = $alerts === [] ? EXIT_OK : EXIT_CRITICAL;
        $report = [
            'status' => $level,
            'checked_at' => $now->format(DateTimeInterface::ATOM),
            'queue' => [
                'pending_count' => count($pending),
                'last_success_at' => $lastSuccess->format(DateTimeInterface::ATOM),
                'minutes_since_success' => $silenceMinutes,
                'oldest_pending' => $oldest === null ? null : [
                    'id' => $oldest['id'] ?? null,
                    'order_no' => $oldest['order_no'] ?? null,
                    'attempts' => $oldest['attempts'] ?? null,
                    'created_at' => $oldest['created_at'] ?? null,
                ],
            ],
            'alerts' => $alerts,
            'recommended_action' => $alerts === []
                ? 'No action required.'
                : 'Inspect the first failed job, correct its data or dependency, then replay it with idempotency verification.',
        ];
    } catch (Throwable $exception) {
        [$exitCode, $report] = invalid($exception->getMessage());
    }
}

echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
exit($exitCode);
