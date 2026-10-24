<?php

namespace Tests\Feature;

/** รัน IsolationTest ชุดเดิมโดยระบุ tenant ผ่าน header */
class IsolationViaHeaderTest extends IsolationTest
{
    protected string $tenantVia = 'header';
}
