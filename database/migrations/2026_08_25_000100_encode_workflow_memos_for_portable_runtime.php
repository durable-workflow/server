<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Workflow\V2\Support\MemoPayload;

return new class extends Migration
{
    public function up(): void
    {
        $this->rewrite(static fn (mixed $value): array => MemoPayload::envelope($value));
    }

    public function down(): void
    {
        $this->rewrite(static function (mixed $value): mixed {
            if (! is_array($value)) {
                return $value;
            }

            return MemoPayload::decode($value);
        });
    }

    private function rewrite(callable $transform): void
    {
        DB::table('workflow_memos')
            ->orderBy('id')
            ->chunkById(100, static function ($rows) use ($transform): void {
                foreach ($rows as $row) {
                    $value = is_string($row->value)
                        ? json_decode($row->value, true, flags: JSON_THROW_ON_ERROR)
                        : $row->value;

                    DB::table('workflow_memos')
                        ->where('id', $row->id)
                        ->update([
                            'value' => json_encode($transform($value), JSON_THROW_ON_ERROR),
                        ]);
                }
            });
    }
};
