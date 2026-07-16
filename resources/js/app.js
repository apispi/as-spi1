import { createApp } from 'vue';
import { createPinia } from 'pinia';
import axios from 'axios';
import App from './App.vue';
import router from './router';

// A 419 means the CSRF token went stale (session expired while the tab sat
// open). Refresh the XSRF cookie with a cheap GET and replay the request
// once; if it fails again, fall through to the normal error path.
axios.interceptors.response.use(null, async (error) => {
    const { config, response } = error;
    if (response?.status === 419 && config && !config._csrfRetried) {
        config._csrfRetried = true;
        await axios.get('/', { headers: { Accept: 'text/html' } });
        return axios(config);
    }
    return Promise.reject(error);
});

const app = createApp(App);
const pinia = createPinia();

app.use(pinia);
app.use(router);
app.mount('#app');
