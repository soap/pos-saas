import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '@pos/stores/auth';
import { useSessionStore } from '@pos/stores/session';

export const router = createRouter({
    history: createWebHistory('/pos/'),
    routes: [
        { path: '/login', name: 'login', component: () => import('@pos/views/LoginView.vue'), meta: { guest: true } },
        { path: '/stores', name: 'stores', component: () => import('@pos/views/StoreSelectView.vue') },
        { path: '/', name: 'register', component: () => import('@pos/views/RegisterView.vue'), meta: { needsStore: true } },
        { path: '/kitchen', name: 'kitchen', component: () => import('@pos/views/KitchenView.vue'), meta: { needsStore: true } },
        {
            path: '/manage',
            name: 'manage',
            component: () => import('@pos/views/manage/ManageLayout.vue'),
            meta: { needsStore: true },
            children: [
                { path: 'reports', name: 'manage.reports', component: () => import('@pos/views/manage/ReportsView.vue') },
                { path: 'products', name: 'manage.products', component: () => import('@pos/views/manage/ProductsView.vue') },
                { path: 'categories', name: 'manage.categories', component: () => import('@pos/views/manage/CategoriesView.vue') },
                { path: 'modifiers', name: 'manage.modifiers', component: () => import('@pos/views/manage/ModifiersView.vue') },
                { path: 'tables', name: 'manage.tables', component: () => import('@pos/views/manage/TablesView.vue') },
                { path: 'stores', name: 'manage.stores', component: () => import('@pos/views/manage/StoresView.vue') },
                { path: 'members', name: 'manage.members', component: () => import('@pos/views/manage/MembersView.vue') },
                { path: 'payment-accounts', name: 'manage.payment-accounts', component: () => import('@pos/views/manage/PaymentAccountsView.vue') },
            ],
        },
        { path: '/receipt/:orderId', name: 'receipt', component: () => import('@pos/views/ReceiptView.vue'), props: true, meta: { needsStore: true } },
        { path: '/:pathMatch(.*)*', redirect: '/' },
    ],
});

router.beforeEach((to) => {
    const auth = useAuthStore();
    const session = useSessionStore();

    if (to.meta.guest) {
        return auth.isAuthenticated ? { name: 'register' } : true;
    }
    if (!auth.isAuthenticated) {
        return { name: 'login', query: to.fullPath !== '/' ? { redirect: to.fullPath } : {} };
    }
    if (to.meta.needsStore && !session.hasStore) {
        return { name: 'stores' };
    }

    return true;
});

declare module 'vue-router' {
    interface RouteMeta {
        guest?: boolean;
        needsStore?: boolean;
    }
}
