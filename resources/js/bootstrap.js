import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Sanctum SPA mode: send the session cookie and let axios translate the
// XSRF-TOKEN cookie into the X-XSRF-TOKEN header automatically, so the
// Blade UI's JS can call our own /api/v1/* endpoints authenticated.
window.axios.defaults.withCredentials = true;
window.axios.defaults.withXSRFToken = true;
