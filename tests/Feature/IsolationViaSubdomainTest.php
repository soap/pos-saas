<?php

namespace Tests\Feature;

/** รัน IsolationTest ชุดเดิมโดยระบุ tenant ผ่าน subdomain */
class IsolationViaSubdomainTest extends IsolationTest
{
    protected string $tenantVia = 'subdomain';

    protected function setUp(): void
    {
        parent::setUp();
        config(['pos.tenancy.subdomain_base' => 'pos.test']);
    }
}
