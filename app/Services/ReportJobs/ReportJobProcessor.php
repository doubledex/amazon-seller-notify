<?php

namespace App\Services\ReportJobs;

use App\Models\ReportJob;

interface ReportJobProcessor
{
    public function process(ReportJob $job, array $rows): array;
}
