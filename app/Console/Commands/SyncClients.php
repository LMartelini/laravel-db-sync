<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncClients extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:sync-clients';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // $this->info('teste');

        $this->syncFromTo('local', 'nuvem', 'local_to_nuvem');
        $this->syncFromTo('nuvem', 'local', 'nuvem_to_local');

        $this->info('sincronização concluída com sucesso');
    }

    protected function syncFromTo(string $sourceConnection, string $targetConnection, string $direction)
    {
        $clients = DB::connection($sourceConnection)
            ->table('clients')
            ->where('updated_at', '>=', now()->subMinutes(10)) 
            ->get();

        foreach ($clients as $client) {
            $alreadySynced = DB::connection($sourceConnection)
                ->table('sync_logs')
                ->where('record_id', $client->id)
                ->where('table_name', 'clients')
                ->where('direction', $direction)
                ->exists();

            if ($alreadySynced) {
                continue;
            }

            $target = DB::connection($targetConnection)
                ->table('clients')
                ->where('id', $client->id)
                ->first();

            if ($target) {
                if ($client->updated_at > $target->updated_at) {
                    DB::connection($targetConnection)
                        ->table('clients')
                        ->where('id', $client->id)
                        ->update([
                            'name' => $client->name,
                            'email' => $client->email,
                            'updated_at' => $client->updated_at,
                        ]);

                    $action = 'update';
                } else {
                    continue;
                }
            } else {
                DB::connection($targetConnection)
                    ->table('clients')
                    ->insert([
                        'id' => $client->id,
                        'name' => $client->name,
                        'email' => $client->email,
                        'created_at' => $client->created_at,
                        'updated_at' => $client->updated_at,
                    ]);

                $action = 'insert';
            }

            DB::connection($sourceConnection)->table('sync_logs')->insert([
                'record_id' => $client->id,
                'table_name' => 'clients',
                'action' => $action,
                'direction' => $direction,
                'synced_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->line("{$action} de {$sourceConnection} → {$targetConnection} [{$client->id}]");
        }
    }
}
