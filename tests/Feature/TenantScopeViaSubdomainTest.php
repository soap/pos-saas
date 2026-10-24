<?php

namespace Tests\Feature;

/** รัน TenantScopeTest ชุดเดิมโดยระบุ tenant ผ่าน subdomain */
class TenantScopeViaSubdomainTest extends TenantScopeTest
{
    protected string $tenantVia = 'subdomain';

    protected function setUp(): void
    {
        parent::setUp();
        config(['pos.tenancy.subdomain_base' => 'pos.test']);
    }
}
