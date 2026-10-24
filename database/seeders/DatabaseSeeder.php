<?php

namespace Database\Seeders;

use App\Modules\Billing\Models\Plan;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\ModifierGroup;
use App\Modules\Catalog\Models\ModifierOption;
use App\Modules\Catalog\Models\Product;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\RbacService;
use App\Modules\Tenancy\Models\StoreMembership;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantMembership;
use App\Modules\Tenancy\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * ข้อมูลตั้งต้น + ร้านตัวอย่าง (ร้านก๋วยเตี๋ยวนำร่อง)
 *
 * บัญชีทดสอบ (รหัสผ่าน: password):
 *   admin@pos.test    platform admin
 *   owner@pos.test    เจ้าของร้าน
 *   cashier@pos.test  แคชเชียร์
 *   kitchen@pos.test  ครัว
 */
class DatabaseSeeder extends Seeder
{
    public function run(RbacService $rbac, TenantProvisioner $provisioner, TenantContext $ctx): void
    {
        // seeder ไม่มี request/tenant context → ระบุ tenant_id เองทุก record
        $ctx->withoutTenancy(fn () => $this->seed($rbac, $provisioner));
    }

    private function seed(RbacService $rbac, TenantProvisioner $provisioner): void
    {
        $rbac->syncSystemRoles();

        Plan::query()->updateOrCreate(['code' => 'starter'], [
            'name' => 'Starter', 'price_satang' => 0, 'billing_interval' => 'month',
            'limits' => ['max_stores' => 1, 'max_users' => 5], 'is_active' => true,
        ]);
        Plan::query()->updateOrCreate(['code' => 'pro'], [
            'name' => 'Pro', 'price_satang' => 59900, 'billing_interval' => 'month',
            'limits' => ['max_stores' => 5, 'max_users' => 30], 'is_active' => true,
        ]);

        $admin = User::query()->firstOrCreate(['email' => 'admin@pos.test'], ['name' => 'Platform Admin', 'password' => 'password', 'status' => 'active']);
        $admin->forceFill(['is_platform_admin' => true])->save();

        if (Tenant::query()->where('slug', 'p-prateep')->exists()) {
            return;
        }

        $tenant = $provisioner->provision([
            'name' => 'ร้านก๋วยเตี๋ยว ป.ประทีป',
            'slug' => 'p-prateep',
            'plan_code' => 'starter',
            'owner' => ['name' => 'เจ้าของร้าน', 'email' => 'owner@pos.test', 'password' => 'password'],
            'store' => ['code' => 'AONANG', 'name' => 'สาขาอ่าวนาง', 'timezone' => 'Asia/Bangkok'],
        ]);
        $store = $tenant->stores()->first();

        foreach (['cashier' => 'แคชเชียร์', 'kitchen' => 'ครัว'] as $role => $name) {
            $user = User::query()->firstOrCreate(['email' => "{$role}@pos.test"], ['name' => $name, 'password' => 'password', 'status' => 'active']);
            $m = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'status' => 'active', 'joined_at' => now()]);
            StoreMembership::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'user_id' => $user->id, 'status' => 'active']);
            $rbac->assign($m, $rbac->findAssignableRole($tenant->id, $role), $store->id);
        }

        $this->seedMenu($tenant->id);

        $this->command?->info("Tenant: {$tenant->id}");
        $this->command?->info("Store:  {$store->id}");
    }

    private function seedMenu(string $tenantId): void
    {
        $t = ['tenant_id' => $tenantId];
        $noodle = Category::create($t + ['name' => 'ก๋วยเตี๋ยว', 'sort_order' => 1]);
        $side = Category::create($t + ['name' => 'ของทานเล่น', 'sort_order' => 2]);
        $drink = Category::create($t + ['name' => 'เครื่องดื่ม', 'sort_order' => 3]);

        $group = function (string $name, int $min, int $max, bool $required, array $options) use ($t) {
            $g = ModifierGroup::create($t + ['name' => $name, 'min_select' => $min, 'max_select' => $max, 'is_required' => $required]);
            foreach ($options as $i => [$label, $delta]) {
                ModifierOption::create($t + ['modifier_group_id' => $g->id, 'name' => $label, 'price_delta_satang' => $delta, 'sort_order' => $i]);
            }

            return $g;
        };
        $noodleType = $group('เส้น', 1, 1, true, [['เส้นเล็ก', 0], ['เส้นใหญ่', 0], ['เส้นหมี่', 0], ['บะหมี่', 0], ['วุ้นเส้น', 0]]);
        $soup = $group('น้ำซุป', 1, 1, true, [['น้ำใส', 0], ['น้ำตก', 0], ['ต้มยำ', 0], ['แห้ง', 0]]);
        $size = $group('ขนาด', 0, 1, false, [['พิเศษ', 1000]]);
        $extra = $group('เพิ่ม', 0, 4, false, [['ไข่ยางมะตูม', 1000], ['ลูกชิ้นเพิ่ม', 1500], ['ไม่ใส่ผัก', 0], ['ไม่ใส่ถั่วงอก', 0]]);
        $ice = $group('ความหวาน', 0, 1, false, [['หวานน้อย', 0], ['ไม่หวาน', 0]]);

        $products = [
            [$noodle, 'NDL-PORK', 'ก๋วยเตี๋ยวหมู', 5000, [$noodleType, $soup, $size, $extra]],
            [$noodle, 'NDL-BEEF', 'ก๋วยเตี๋ยวเนื้อ', 6000, [$noodleType, $soup, $size, $extra]],
            [$noodle, 'NDL-BOAT', 'ก๋วยเตี๋ยวเรือ', 5000, [$noodleType, $size, $extra]],
            [$noodle, 'NDL-YENTAFO', 'เย็นตาโฟ', 5500, [$noodleType, $size, $extra]],
            [$side, 'SIDE-WONTON', 'เกี๊ยวทอด', 4000, []],
            [$side, 'SIDE-PORKRIND', 'แคบหมู', 2000, []],
            [$drink, 'DRK-THAITEA', 'ชาไทยเย็น', 3500, [$ice]],
            [$drink, 'DRK-WATER', 'น้ำเปล่า', 1000, []],
        ];
        foreach ($products as $i => [$cat, $sku, $name, $price, $groups]) {
            $p = Product::create($t + ['category_id' => $cat->id, 'sku' => $sku, 'name' => $name, 'base_price_satang' => $price, 'sort_order' => $i]);
            $sync = [];
            foreach ($groups as $j => $g) {
                $sync[$g->id] = ['tenant_id' => $tenantId, 'sort_order' => $j];
            }
            $p->modifierGroups()->sync($sync);
        }
    }
}
