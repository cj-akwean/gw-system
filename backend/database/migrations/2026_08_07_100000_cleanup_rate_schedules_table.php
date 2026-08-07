<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Data cleanup for the rate_schedules table:
     *
     * 1. Collapse duplicate same-name schedules (keep the lowest id, repoint
     *    connections / invoices / tiers at the survivors).
     * 2. Remove the explicitly incomplete connections created by manual
     *    testing (guarded on their exact test identifiers and on having zero
     *    dependents, so real data is never matched).
     * 3. Remove orphan rate schedules (no connections, invoices, or tiers
     *    reference them). Safe today because no UI exists to create rate
     *    schedules — anything unreferenced is legacy/manual data.
     *
     * Deliberately NOT enforced with a unique index on name: the domain allows
     * two open-ended schedules that share a name across different billing
     * periods, and the CRM dropdown already disambiguates identical names via
     * the `name · effective_from` label.
     */
    public function up(): void
    {
        $duplicates = DB::table('rate_schedules')
            ->select('name')
            ->groupBy('name')
            ->havingRaw('count(*) > 1')
            ->pluck('name');

        foreach ($duplicates as $name) {
            $ids = DB::table('rate_schedules')->where('name', $name)->orderBy('id')->pluck('id');
            $keep = $ids->shift();

            foreach ($ids as $doomed) {
                DB::table('service_connections')->where('rate_schedule_id', $doomed)->update(['rate_schedule_id' => $keep]);
                DB::table('invoices')->where('rate_schedule_id', $doomed)->update(['rate_schedule_id' => $keep]);
                DB::table('rate_tiers')->where('rate_schedule_id', $doomed)->update(['rate_schedule_id' => $keep]);
                DB::table('rate_schedules')->where('id', $doomed)->delete();
            }
        }

        foreach (['GW-NEW-001', 'GW-00112'] as $accountNumber) {
            $connection = DB::table('service_connections')->where('account_number', $accountNumber)->first();

            if (! $connection) {
                continue;
            }

            $hasDependents = DB::table('invoices')->where('service_connection_id', $connection->id)->exists()
                || DB::table('meter_readings')->where('service_connection_id', $connection->id)->exists()
                || DB::table('connection_links')->where('service_connection_id', $connection->id)->exists();

            if ($hasDependents) {
                continue;
            }

            DB::table('service_connections')->where('id', $connection->id)->delete();
        }

        DB::table('rate_schedules as rs')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('service_connections')
                    ->whereColumn('rate_schedule_id', 'rs.id');
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('invoices')
                    ->whereColumn('rate_schedule_id', 'rs.id');
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('rate_tiers')
                    ->whereColumn('rate_schedule_id', 'rs.id');
            })
            ->delete();
    }

    public function down(): void
    {
        // Data cleanup is destructive; there is nothing safe to rebuild in a rollback.
    }
};
