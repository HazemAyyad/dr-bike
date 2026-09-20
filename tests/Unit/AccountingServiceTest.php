<?php

namespace Tests\Unit;

use App\Services\AccountingService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountingServiceTest extends TestCase
{
    public function test_balanced_lines_are_normalized_without_database_lookup(): void
    {
        $lines = app(AccountingService::class)->validateAndNormalizeLines([
            ['account_id' => 10, 'debit' => 25.12345, 'credit' => 0, 'due_date' => '2026-10-01'],
            ['account_id' => 20, 'debit' => 0, 'credit' => 25.12345],
        ]);

        $this->assertSame(25.1235, $lines[0]['debit']);
        $this->assertSame('2026-10-01', $lines[0]['due_date']);
        $this->assertSame(25.1235, $lines[1]['credit']);
    }

    #[DataProvider('invalidLines')]
    public function test_invalid_journal_lines_are_rejected(array $lines): void
    {
        $this->expectException(ValidationException::class);
        app(AccountingService::class)->validateAndNormalizeLines($lines);
    }

    public static function invalidLines(): array
    {
        return [
            'one line' => [[['account_id' => 1, 'debit' => 10]]],
            'unbalanced' => [[
                ['account_id' => 1, 'debit' => 10],
                ['account_id' => 2, 'credit' => 9],
            ]],
            'both sides' => [[
                ['account_id' => 1, 'debit' => 10, 'credit' => 1],
                ['account_id' => 2, 'credit' => 9],
            ]],
            'zero line' => [[
                ['account_id' => 1, 'debit' => 0, 'credit' => 0],
                ['account_id' => 2, 'credit' => 1],
            ]],
        ];
    }
}
