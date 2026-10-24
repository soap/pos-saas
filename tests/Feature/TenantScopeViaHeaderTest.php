<?php

namespace Tests\Feature;

/** รัน TenantScopeTest ชุดเดิมโดยระบุ tenant ผ่าน header */
class TenantScopeViaHeaderTest extends TenantScopeTest
{
    protected string $tenantVia = 'header';
}
