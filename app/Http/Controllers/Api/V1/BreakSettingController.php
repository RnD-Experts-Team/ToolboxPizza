<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateBreakSettingsRequest;
use App\Services\Breaks\BreakSettingsService;
use Illuminate\Http\Request;

class BreakSettingController extends Controller
{
    public function __construct(private readonly BreakSettingsService $settings) {}

    /**
     * Creates the user's settings row on first read, from the configured
     * default — there is no separate "set up my breaks" step.
     *
     * @return array<string, mixed>
     */
    public function show(Request $request): array
    {
        return ['data' => $this->settings->present($request->user())];
    }

    /**
     * @return array<string, mixed>
     */
    public function update(UpdateBreakSettingsRequest $request): array
    {
        $this->settings->updateAllowance(
            $request->user(),
            (int) $request->validated('daily_allowance_minutes'),
        );

        return ['data' => $this->settings->present($request->user())];
    }
}
