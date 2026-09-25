<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Panel, Card, Badge, Button, Description, Tabs, TabList, TabTrigger, TabContent,
    Table, TableColumns, TableColumn, TableRows, TableRow, TableCell, ConfirmationModal,
} from '@statamic/cms/ui';

/**
 * The App API as this site has it: every endpoint (registered or not, and
 * why), the areas and their addons, the settings that shape a request, the
 * events with who listens, and the personal access tokens.
 */
const props = defineProps([
    'areas',            // [{ handle, label, package, enabled, installed, active, endpoints }]
    'endpoints',        // [{ area, method, path, summary, auth, elevated, team, status, errors, registered }]
    'config',           // { prefix, guard, middleware, rate_limit, team_header, team_fallback, stateful_domains, csrf_cookie_url, openapi_url, two_factor, elevated_sessions, registration, settings_url }
    'events',           // [{ handle, label, description, automations, webhooks }]
    'integrations',     // { automations: { installed, url }, webhook_manager: {…}, activity: {…} }
    'tokens',           // { enabled, table, rows: [{ id, name, abilities, last_used_at, expires_at, created_at, user, revoke_url }] }
    'errors',           // { code: status }
    'canManageTokens',
    'openapiCpUrl',
]);

const initial = new URLSearchParams(window.location.search).get('tab');
const tab = ref(['endpoints', 'setup', 'wiring', 'tokens'].includes(initial) ? initial : 'endpoints');

const areaLabel = (handle) => props.areas.find((a) => a.handle === handle)?.label ?? handle;

const groups = computed(() => props.areas
    .map((area) => ({ area, rows: props.endpoints.filter((e) => e.area === area.handle) }))
    .filter((group) => group.rows.length));

const methodColor = { GET: 'sky', POST: 'green', PUT: 'amber', DELETE: 'red' };

const errorList = computed(() => Object.entries(props.errors).map(([code, status]) => ({ code, status })));

const siblings = computed(() => [
    { key: 'automations', label: __('Automations'), text: __('Both token events are triggers in the flow builder, group "App API".') },
    { key: 'webhook_manager', label: __('Webhook Manager'), text: __('Both token events are webhook triggers.') },
    { key: 'activity', label: __('Activity'), text: __('Both token events are written to the activity log, subject user.') },
]);

function areaState(area) {
    if (area.active) return { color: 'green', text: __('Active') };
    if (! area.installed) return { color: 'default', text: __('Not installed') };
    return { color: 'amber', text: __('Switched off') };
}

function date(value) {
    return value ? new Date(value).toLocaleString() : '–';
}

const revoking = ref(null);
const busy = ref(false);

function revoke() {
    busy.value = true;
    router.delete(revoking.value.revoke_url, {
        preserveScroll: true,
        onFinish: () => { busy.value = false; revoking.value = null; },
    });
}
</script>

<template>
    <Head :title="__('App API')" />

    <div class="max-w-page mx-auto">
        <Header :title="__('App API')" icon="code">
            <Button v-if="config.settings_url" :href="config.settings_url" :text="__('Settings')" />
            <Button :href="openapiCpUrl" target="_blank" :text="__('OpenAPI description')" variant="primary" />
        </Header>

        <Tabs v-model="tab">
            <TabList class="mb-6">
                <TabTrigger name="endpoints" :text="__('Endpoints')" />
                <TabTrigger name="setup" :text="__('Areas and setup')" />
                <TabTrigger name="wiring" :text="__('Wiring')" />
                <TabTrigger name="tokens" :text="__('Access tokens')" />
            </TabList>

            <TabContent name="endpoints">
                <Panel
                    v-for="group in groups"
                    :key="group.area.handle"
                    :heading="group.area.label"
                    :subheading="group.area.active ? group.area.package : __('Not registered on this site: :package', { package: group.area.package })"
                    :data-area="group.area.handle"
                >
                    <Card>
                        <Table>
                            <TableColumns>
                                <TableColumn class="w-24">{{ __('Method') }}</TableColumn>
                                <TableColumn>{{ __('Path') }}</TableColumn>
                                <TableColumn>{{ __('Requires') }}</TableColumn>
                                <TableColumn class="text-end">{{ __('Success') }}</TableColumn>
                            </TableColumns>
                            <TableRows>
                                <TableRow v-for="endpoint in group.rows" :key="endpoint.method + endpoint.path" :class="{ 'opacity-50': ! endpoint.registered }">
                                    <TableCell>
                                        <Badge pill :color="methodColor[endpoint.method] ?? 'default'" :text="endpoint.method" />
                                    </TableCell>
                                    <TableCell>
                                        <div class="font-mono text-sm">{{ endpoint.path }}</div>
                                        <Description :text="endpoint.summary" class="mt-1" />
                                    </TableCell>
                                    <TableCell>
                                        <div class="flex flex-wrap gap-1">
                                            <Badge v-if="endpoint.auth" pill color="blue" :text="__('Session or token')" />
                                            <Badge v-else pill :text="__('Public')" />
                                            <Badge v-if="endpoint.elevated" pill color="violet" :text="__('Confirmation')" />
                                            <Badge v-if="endpoint.team" pill color="teal" :text="config.team_header" />
                                            <Badge v-if="! endpoint.registered" pill color="amber" :text="__('Off')" />
                                        </div>
                                    </TableCell>
                                    <TableCell class="text-end font-mono text-sm tabular-nums">{{ endpoint.status }}</TableCell>
                                </TableRow>
                            </TableRows>
                        </Table>
                    </Card>
                </Panel>
            </TabContent>

            <TabContent name="setup">
                <Panel :heading="__('Areas')" :subheading="__('An area needs its switch in config/app-api.php and its addon. Otherwise its paths answer 404.')">
                    <Card>
                        <Table>
                            <TableColumns>
                                <TableColumn>{{ __('Area') }}</TableColumn>
                                <TableColumn>{{ __('Addon') }}</TableColumn>
                                <TableColumn>{{ __('State') }}</TableColumn>
                                <TableColumn class="text-end">{{ __('Endpoints') }}</TableColumn>
                            </TableColumns>
                            <TableRows>
                                <TableRow v-for="area in areas" :key="area.handle" :data-area-state="area.handle">
                                    <TableCell>
                                        <div class="font-medium">{{ area.label }}</div>
                                        <div class="font-mono text-xs text-gray-500 dark:text-gray-400">app-api.areas.{{ area.handle }}</div>
                                    </TableCell>
                                    <TableCell class="font-mono text-sm">{{ area.package }}</TableCell>
                                    <TableCell><Badge pill :color="areaState(area).color" :text="areaState(area).text" /></TableCell>
                                    <TableCell class="text-end tabular-nums">{{ area.endpoints }}</TableCell>
                                </TableRow>
                            </TableRows>
                        </Table>
                    </Card>
                </Panel>

                <Panel :heading="__('Requests')" :subheading="__('Read when the routes load. Change them in config/app-api.php.')">
                    <Card>
                        <dl class="grid gap-x-8 gap-y-4 sm:grid-cols-2">
                            <div>
                                <dt class="text-sm font-medium">{{ __('Prefix') }}</dt>
                                <dd class="font-mono text-sm text-gray-700 dark:text-gray-300">{{ config.prefix }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium">{{ __('Guard') }}</dt>
                                <dd class="font-mono text-sm text-gray-700 dark:text-gray-300">{{ config.guard }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium">{{ __('Middleware') }}</dt>
                                <dd class="font-mono text-sm text-gray-700 dark:text-gray-300">{{ config.middleware.join(', ') || '–' }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium">{{ __('Requests per minute') }}</dt>
                                <dd class="font-mono text-sm text-gray-700 dark:text-gray-300">{{ config.rate_limit || __('No limit') }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium">{{ __('Team header') }}</dt>
                                <dd class="font-mono text-sm text-gray-700 dark:text-gray-300">{{ config.team_header }}</dd>
                                <Description :text="config.team_fallback ? __('Without the header: the current team of the user.') : __('Without the header: no team.')" />
                            </div>
                            <div>
                                <dt class="text-sm font-medium">{{ __('CSRF cookie') }}</dt>
                                <dd class="font-mono text-sm text-gray-700 dark:text-gray-300">{{ config.csrf_cookie_url || '–' }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium">{{ __('Sanctum stateful domains') }}</dt>
                                <dd class="font-mono text-sm text-gray-700 dark:text-gray-300">{{ config.stateful_domains.join(', ') || '–' }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium">{{ __('OpenAPI') }}</dt>
                                <dd class="font-mono text-sm">
                                    <a v-if="config.openapi_url" :href="config.openapi_url" target="_blank" class="text-ui-accent-text underline">{{ config.openapi_url }}</a>
                                    <span v-else class="text-gray-500">{{ __('Not served, see app-api.routes.openapi') }}</span>
                                </dd>
                            </div>
                        </dl>
                        <div class="mt-6 flex flex-wrap gap-2">
                            <Badge pill :color="config.registration ? 'green' : 'default'" :text="config.registration ? __('Registration on') : __('Registration off')" />
                            <Badge pill :color="config.two_factor ? 'green' : 'default'" :text="config.two_factor ? __('Two-factor login') : __('No two-factor login')" />
                            <Badge pill :color="config.elevated_sessions ? 'green' : 'amber'" :text="config.elevated_sessions ? __('Confirmation before account changes') : __('No confirmation before account changes')" />
                        </div>
                    </Card>
                </Panel>

                <Panel :heading="__('Error codes')" :subheading="__('Every error has the shape {error: {code, message, field?, details?}}. The code is stable.')">
                    <Card>
                        <div class="flex flex-wrap gap-2">
                            <Badge v-for="error in errorList" :key="error.code" pill :text="error.code" :append="String(error.status)" />
                        </div>
                    </Card>
                </Panel>
            </TabContent>

            <TabContent name="wiring">
                <Panel :heading="__('Connected addons')">
                    <Card>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div v-for="sibling in siblings" :key="sibling.key" class="flex items-start gap-3" :data-integration="sibling.key">
                                <Badge
                                    pill
                                    :color="integrations[sibling.key].installed ? 'green' : 'default'"
                                    :text="integrations[sibling.key].installed ? __('Installed') : __('Not installed')"
                                />
                                <div>
                                    <a v-if="integrations[sibling.key].installed && integrations[sibling.key].url" :href="integrations[sibling.key].url" class="font-medium text-ui-accent-text underline">{{ sibling.label }}</a>
                                    <span v-else class="font-medium">{{ sibling.label }}</span>
                                    <Description :text="sibling.text" />
                                </div>
                            </div>
                        </div>
                    </Card>
                </Panel>

                <Panel :heading="__('Events')" :subheading="__('Only what this addon does itself. Team, account and payment events come from their own addons.')">
                    <Card>
                        <Table>
                            <TableColumns>
                                <TableColumn>{{ __('Event') }}</TableColumn>
                                <TableColumn>{{ __('Mail') }}</TableColumn>
                                <TableColumn class="text-end">{{ __('Automations') }}</TableColumn>
                                <TableColumn class="text-end">{{ __('Webhooks') }}</TableColumn>
                            </TableColumns>
                            <TableRows>
                                <TableRow v-for="event in events" :key="event.handle" :data-event="event.handle">
                                    <TableCell>
                                        <div class="font-medium">{{ event.label }}</div>
                                        <div class="font-mono text-xs text-gray-500 dark:text-gray-400">{{ event.handle }}</div>
                                        <Description :text="event.description" class="mt-1" />
                                    </TableCell>
                                    <TableCell><span class="text-gray-500 dark:text-gray-400">{{ __('None') }}</span></TableCell>
                                    <TableCell class="text-end tabular-nums">
                                        <a v-if="integrations.automations.installed && integrations.automations.url" :href="integrations.automations.url" class="text-ui-accent-text underline">{{ event.automations }}</a>
                                        <span v-else class="text-gray-400">–</span>
                                    </TableCell>
                                    <TableCell class="text-end tabular-nums">
                                        <a v-if="integrations.webhook_manager.installed && integrations.webhook_manager.url" :href="integrations.webhook_manager.url" class="text-ui-accent-text underline">{{ event.webhooks }}</a>
                                        <span v-else class="text-gray-400">–</span>
                                    </TableCell>
                                </TableRow>
                            </TableRows>
                        </Table>
                    </Card>
                </Panel>
            </TabContent>

            <TabContent name="tokens">
                <Panel :heading="__('Access tokens')" :subheading="__('Personal access tokens of Sanctum, for clients without a browser session. Only their hash is stored.')">
                    <Card>
                        <Description v-if="! tokens.enabled" :text="__('Tokens are switched off. Switch them on with app-api.areas.tokens; the user model needs Sanctum\'s HasApiTokens (Eloquent users only).')" />
                        <Description v-else-if="! tokens.table" :text="__('The table personal_access_tokens is missing. Publish and run Sanctum\'s migration.')" />
                        <Description v-else-if="! tokens.rows.length" :text="__('No token yet. Users create them with POST tokens.')" />
                        <Table v-else>
                            <TableColumns>
                                <TableColumn>{{ __('Name') }}</TableColumn>
                                <TableColumn>{{ __('User') }}</TableColumn>
                                <TableColumn>{{ __('Abilities') }}</TableColumn>
                                <TableColumn>{{ __('Last used') }}</TableColumn>
                                <TableColumn>{{ __('Expires') }}</TableColumn>
                                <TableColumn v-if="canManageTokens" />
                            </TableColumns>
                            <TableRows>
                                <TableRow v-for="token in tokens.rows" :key="token.id">
                                    <TableCell class="font-medium">{{ token.name }}</TableCell>
                                    <TableCell>
                                        <a v-if="token.user?.edit_url" :href="token.user.edit_url" class="text-ui-accent-text underline">{{ token.user.email }}</a>
                                        <span v-else>{{ token.user?.email ?? '–' }}</span>
                                    </TableCell>
                                    <TableCell class="font-mono text-xs">{{ token.abilities.join(', ') }}</TableCell>
                                    <TableCell class="text-sm">{{ date(token.last_used_at) }}</TableCell>
                                    <TableCell class="text-sm">{{ token.expires_at ? date(token.expires_at) : __('Never') }}</TableCell>
                                    <TableCell v-if="canManageTokens" class="text-end">
                                        <Button size="sm" variant="ghost" :text="__('Revoke')" @click="revoking = token" />
                                    </TableCell>
                                </TableRow>
                            </TableRows>
                        </Table>
                    </Card>
                </Panel>
            </TabContent>
        </Tabs>

        <ConfirmationModal
            :open="revoking !== null"
            :title="__('Revoke token')"
            :body-text="revoking ? __('The client using \':name\' is signed out at once. This cannot be undone.', { name: revoking.name }) : ''"
            :button-text="__('Revoke')"
            :busy="busy"
            danger
            @confirm="revoke"
            @cancel="revoking = null"
            @update:open="(open) => { if (! open) revoking = null; }"
        />
    </div>
</template>
