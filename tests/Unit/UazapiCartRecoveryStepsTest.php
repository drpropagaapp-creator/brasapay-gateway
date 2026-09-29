<?php

namespace Tests\Unit;

use App\Support\UazapiCartRecoverySteps;
use Tests\TestCase;

class UazapiCartRecoveryStepsTest extends TestCase
{
    public function test_converts_ui_steps_to_minutes(): void
    {
        $steps = UazapiCartRecoverySteps::fromUiInput([
            ['delay_value' => 10, 'delay_unit' => 'minutes', 'message' => 'A'],
            ['delay_value' => 2, 'delay_unit' => 'hours', 'message' => 'B'],
            ['delay_value' => 1, 'delay_unit' => 'days', 'message' => 'C'],
        ]);

        $this->assertSame(10, $steps[0]['delay_minutes']);
        $this->assertSame(120, $steps[1]['delay_minutes']);
        $this->assertSame(1440, $steps[2]['delay_minutes']);
    }

    public function test_empty_pix_ui_steps_are_allowed(): void
    {
        $this->assertSame([], UazapiCartRecoverySteps::fromUiInput([]));
    }

    public function test_pix_defaults_use_half_hour_then_two_hours(): void
    {
        $steps = UazapiCartRecoverySteps::pixDefaults();

        $this->assertSame(30, $steps[0]['delay_minutes']);
        $this->assertSame(120, $steps[1]['delay_minutes']);
    }
}
