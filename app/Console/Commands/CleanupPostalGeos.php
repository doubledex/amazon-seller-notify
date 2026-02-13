<?php

namespace App\Console\Commands;

use App\Models\PostalCodeGeo;
use Illuminate\Console\Command;

class CleanupPostalGeos extends Command
{
    protected $signature = 'map:cleanup-geos {--delete : Permanently delete invalid rows} {--sample=10 : Number of sample rows to display}';
    protected $description = 'Find (and optionally delete) invalid postal geocode rows.';

    public function handle(): int
    {
        $query = PostalCodeGeo::query()->where(function ($q) {
            $q->whereNull('lat')
                ->orWhereNull('lng')
                ->orWhere('lat', '=', 0)
                ->orWhere('lng', '=', 0)
                ->orWhereRaw('abs(lat) > 90')
                ->orWhereRaw('abs(lng) > 180');
        });

        $count = (clone $query)->count();
        $this->info("Invalid geocode rows: {$count}");

        $sampleSize = max(0, (int) $this->option('sample'));
        if ($sampleSize > 0) {
            $samples = (clone $query)
                ->orderBy('id')
                ->limit($sampleSize)
                ->get(['id', 'country_code', 'postal_code', 'lat', 'lng', 'source']);

            if ($samples->isNotEmpty()) {
                $this->line('Sample rows:');
                foreach ($samples as $row) {
                    $this->line(sprintf(
                        '#%d %s %s lat=%s lng=%s (%s)',
                        $row->id,
                        $row->country_code ?? 'n/a',
                        $row->postal_code ?? 'n/a',
                        $row->lat ?? 'null',
                        $row->lng ?? 'null',
                        $row->source ?? 'n/a'
                    ));
                }
            }
        }

        if (!$this->option('delete')) {
            $this->comment('Dry run only. Re-run with --delete to remove these rows.');
            return Command::SUCCESS;
        }

        $deleted = (clone $query)->delete();
        $this->info("Deleted {$deleted} rows.");
        return Command::SUCCESS;
    }
}
