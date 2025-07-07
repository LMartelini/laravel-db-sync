<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

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
            $this->sync($table, 'local', 'nuvem', "local_to_nuvem");
            $this->sync($table, 'nuvem', 'local', "nuvem_to_local");
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
        $records = DB::connection($connection)
            ->table($table)
            ->get();
        
        foreach ($records as $record) {
            $this->info("Record {$record->id}: deleted_at = " . ($record->deleted_at ?? 'null'));
        }

        return $records;
    }

    protected function alreadySynced(string $connection, string $table, string $recordId, string $direction): bool
    {
        $lastSync = DB::connection($connection)
            ->table('sync_logs')
            ->where('record_id', $recordId)
            ->where('table_name', $table)
            ->where('direction', $direction)
            ->orderBy('synced_at', 'desc')
            ->first();

        if (!$lastSync) {
            return false;
        }

        $currentRecord = DB::connection($connection)
            ->table($table)
            ->where('id', $recordId)
            ->first();

        if (!$currentRecord) {
            return true;
        }

        $lastSyncDate = Carbon::parse($lastSync->synced_at);
        $recordUpdatedAt = Carbon::parse($currentRecord->updated_at);

        if ($recordUpdatedAt->gt($lastSyncDate)) {
            return false;
        }

        return true;
    }

    protected function syncRecord(string $table, $record, string $from, string $to, string $direction)
    {
        $target = DB::connection($to)->table($table)->where('id', $record->id)->first();

        DB::connection($to)->transaction(function () use ($table, $record, $target, $from, $to, $direction) {
            $action = null;

            if ($target) {
                $needsUpdate = $this->needsUpdate($record, $target);

                if ($needsUpdate) {
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

    protected function needsUpdate($record, $target): bool
    {
        $recordUpdatedAt = $record->updated_at ? Carbon::parse($record->updated_at) : null;
        $targetUpdatedAt = $target->updated_at ? Carbon::parse($target->updated_at) : null;

        $needsUpdate = $recordUpdatedAt && $targetUpdatedAt && $recordUpdatedAt->gt($targetUpdatedAt);

        if (property_exists($record, 'deleted_at') && property_exists($target, 'deleted_at')) {
            $recordDeletedAt = $record->deleted_at ? Carbon::parse($record->deleted_at) : null;
            $targetDeletedAt = $target->deleted_at ? Carbon::parse($target->deleted_at) : null;

            if ($recordDeletedAt && !$targetDeletedAt) {
                return true;
            }
            
            if (!$recordDeletedAt && $targetDeletedAt) {
                return true;
            }
            
            if ($recordDeletedAt && $targetDeletedAt && !$recordDeletedAt->equalTo($targetDeletedAt)) {
                return true;
            }
        }

        return $needsUpdate;
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
        $data = collect((array) $record)
            ->except($exclude)
            ->toArray();

        if (isset($data['deleted_at'])) {
            if ($data['deleted_at'] === '' || $data['deleted_at'] === '0000-00-00 00:00:00') {
                $data['deleted_at'] = null;
            }
        }

        return $data;
    }
}