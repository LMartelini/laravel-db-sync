<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncClients extends Command
{
    protected $signature = 'app:sync-clients';
    protected $description = 'Sincroniza múltiplas tabelas entre os bancos local e nuvem';

    protected array $tablesToSync = [
        'clients',
    ];

    public function handle()
    {
        foreach ($this->tablesToSync as $table) {
            $this->sync($table, 'local', 'nuvem', "{$table}_local_to_nuvem");
            $this->sync($table, 'nuvem', 'local', "{$table}_nuvem_to_local");
        }
    }

    protected function sync(string $table, string $from, string $to, string $direction)
    {
        $records = $this->getRecordsToSync($from, $table);

        foreach ($records as $record) {
            if ($this->alreadySynced($from, $table, $record->id, $direction)) {
                continue;
            }

            $this->syncRecord($table, $record, $from, $to, $direction);
        }
    }

    protected function getRecordsToSync(string $connection, string $table)
    {
        return DB::connection($connection)
            ->table($table)
            ->get(); 
    }

    protected function alreadySynced(string $connection, string $table, string $recordId, string $direction): bool
    {
        return DB::connection($connection)
            ->table('sync_logs')
            ->where('record_id', $recordId)
            ->where('table_name', $table)
            ->where('direction', $direction)
            ->exists();
    }

    protected function syncRecord(string $table, $record, string $from, string $to, string $direction)
    {
        $target = DB::connection($to)->table($table)->where('id', $record->id)->first();

        DB::connection($to)->transaction(function () use ($table, $record, $target, $from, $to, $direction) {
            $action = null;

            if ($target) {
                if ($record->updated_at > $target->updated_at) {
                    DB::connection($to)->table($table)->where('id', $record->id)->update(
                        $this->buildDataArray($record, ['id', 'created_at'])
                    );
                    $action = 'update';
                } else {
                    return;
                }
            } else {
                DB::connection($to)->table($table)->insert(
                    $this->buildDataArray($record)
                );
                $action = 'insert';
            }

            $this->logSync($from, $table, $record->id, $direction, $action);
        });
    }

    protected function logSync(string $connection, string $table, string $recordId, string $direction, string $action)
    {
        DB::connection($connection)->table('sync_logs')->insert([
            'record_id' => $recordId,
            'table_name' => $table,
            'action' => $action,
            'direction' => $direction,
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function buildDataArray($record, array $exclude = []): array
    {
        return collect((array) $record)
            ->except($exclude)
            ->toArray();
    }
}
