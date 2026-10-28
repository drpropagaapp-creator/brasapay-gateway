<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Support\PlatformCompanySettings;
use Tests\TestCase;

class PlatformCompanySettingsDocumentNameTest extends TestCase
{
    public function test_document_company_name_uses_legal_name(): void
    {
        Setting::set(PlatformCompanySettings::KEY_LEGAL_NAME, 'JAS MARKETING E DESENVOLVIMENTO LTDA', null);

        $this->assertSame('JAS MARKETING E DESENVOLVIMENTO LTDA', PlatformCompanySettings::documentCompanyName());
    }

    public function test_document_company_name_does_not_fall_back_to_getfy(): void
    {
        config(['app.name' => 'Getfy', 'getfy.app_name' => 'Gatefy']);
        Setting::set(PlatformCompanySettings::KEY_LEGAL_NAME, '', null);

        $this->assertSame('Empresa', PlatformCompanySettings::documentCompanyName());
    }
}
