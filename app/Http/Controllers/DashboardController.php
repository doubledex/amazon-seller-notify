<?php

namespace App\Http\Controllers;

use App\Services\MarketplaceHolidayService;

class DashboardController extends Controller
{
    public function index(MarketplaceHolidayService $holidayService)
    {
        $holidayPanel = $holidayService->holidaysWindow(7, 30);

        return view('dashboard', [
            'holidayPanel' => $holidayPanel,
        ]);
    }
}
