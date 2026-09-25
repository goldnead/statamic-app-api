/**
 * Control Panel entry. The registered name must match what the controller
 * passes to `Inertia::render()`, exactly: a mismatch is a blank screen with
 * nothing in the log.
 */

import Overview from './pages/Overview.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('app-api::Overview', Overview);
});
