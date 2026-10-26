import { createPinia } from 'pinia';
import { createApp } from 'vue';
import { setUnauthenticatedHandler } from '@pos/api/client';
import App from '@pos/App.vue';
import { router } from '@pos/router';
import { useAuthStore } from '@pos/stores/auth';

const app = createApp(App);
app.use(createPinia());
app.use(router);

// token หมดอายุ/ถูก revoke → ล้าง session แล้วกลับหน้า login
setUnauthenticatedHandler(() => {
    useAuthStore().clear();
    void router.replace({ name: 'login' });
});

app.mount('#pos-app');
