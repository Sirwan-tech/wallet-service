<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Rule 7: concurrent requests against the same account must not corrupt the
 * balance. Two simultaneous withdrawals of 60 against a balance of 100 must
 * result in exactly one success and one rejection - never two successes.
 *
 * These tests spawn real OS processes, each with its own database connection,
 * because one PHP process cannot exercise row locking against itself, and
 * because pcntl is not compiled into the php:8.3 image. RefreshDatabase is
 * unusable here: it wraps each test in an uncommitted transaction the child
 * processes could never see. This class uses DatabaseTruncation instead and
 * clears up after itself so the transactional tests around it are unaffected.
 *
 * WHY THE ASSERTIONS LOOK LIKE THIS. Removing ->lockForUpdate() from
 * WalletService::withdraw() does NOT change the final balance: both processes
 * read 100, both write 100 - 60, and the balance still reads 40. What changes
 * is that both callers are told they succeeded and two withdrawal rows are
 * written - 120 leaves an account that held 100. Measured over eight runs of
 * an unlocked variant, every run double-spent. So these tests assert the
 * outcome counts and the ledger row count, and never the balance alone: a
 * test that only checked the balance would pass with the locking deleted.
 */
class ConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        // The child processes COMMIT their rows, so clear them before handing
        // the database back to the transactional tests. Children first: the
        // transactions table has a foreign key onto accounts.
        DB::table('transactions')->delete();
        DB::table('idempotency_keys')->delete();
        DB::table('personal_access_tokens')->delete();
        DB::table('accounts')->delete();

        parent::tearDown();
    }

    /**
     * @return array<int, string> one entry per process: APPLIED or REJECTED
     */
    private function runInParallel(string $action, string $accountId, int $amount, int $processes): array
    {
        // Point the children at exactly the database this test is using.
        $env = [
            'DB_CONNECTION' => 'mysql',
            'DB_HOST'       => config('database.connections.mysql.host'),
            'DB_PORT'       => (string) config('database.connections.mysql.port'),
            'DB_DATABASE'   => config('database.connections.mysql.database'),
            'DB_USERNAME'   => config('database.connections.mysql.username'),
            'DB_PASSWORD'   => config('database.connections.mysql.password'),
            'APP_KEY'       => config('app.key'),
        ];

        // Every child waits for this instant before touching the account, so
        // that booting Laravel does not stagger them out of the race.
        $startAt = (int) ((microtime(true) + 2.5) * 1_000_000);

        $running = [];

        for ($i = 0; $i < $processes; $i++) {
            $process = new Process(
                [
                    PHP_BINARY,
                    base_path('tests/Support/concurrent_operation.php'),
                    $action,
                    $accountId,
                    (string) $amount,
                    (string) $startAt,
                ],
                base_path(),
                $env
            );

            $process->setTimeout(60);
            $process->start();

            $running[] = $process;
        }

        $results = [];

        foreach ($running as $process) {
            $process->wait();

            $output = trim($process->getOutput());

            $this->assertContains($output, ['APPLIED', 'REJECTED'], sprintf(
                'A child process did not finish cleanly. stdout: [%s] stderr: [%s]',
                $output,
                trim($process->getErrorOutput())
            ));

            $results[] = $output;
        }

        return $results;
    }

    public function test_two_simultaneous_withdrawals_of_60_against_100_give_one_success_and_one_rejection(): void
    {
        $account = Account::factory()->withBalance(100)->create();

        $counts = array_count_values($this->runInParallel('withdraw', $account->id, 60, 2));

        $this->assertSame(1, $counts['APPLIED'] ?? 0, 'Exactly one withdrawal must be applied.');
        $this->assertSame(1, $counts['REJECTED'] ?? 0, 'Exactly one withdrawal must be rejected.');

        // The ledger is the real evidence: one row, not two.
        $this->assertSame(1, Transaction::where('account_id', $account->id)->count());
        $this->assertSame(40, $account->fresh()->balance);
    }

    public function test_a_balance_can_only_be_spent_once_however_many_callers_race_for_it(): void
    {
        $account = Account::factory()->withBalance(100)->create();

        $counts = array_count_values($this->runInParallel('withdraw', $account->id, 60, 4));

        $this->assertSame(1, $counts['APPLIED'] ?? 0);
        $this->assertSame(3, $counts['REJECTED'] ?? 0);
        $this->assertSame(1, Transaction::where('account_id', $account->id)->count());
        $this->assertSame(40, $account->fresh()->balance);
    }

    public function test_simultaneous_deposits_are_never_lost(): void
    {
        $account = Account::factory()->withBalance(0)->create();

        $results = $this->runInParallel('deposit', $account->id, 100, 10);

        $this->assertSame(['APPLIED' => 10], array_count_values($results));
        $this->assertSame(10, Transaction::where('account_id', $account->id)->count());
        $this->assertSame(1000, $account->fresh()->balance);
    }
}
