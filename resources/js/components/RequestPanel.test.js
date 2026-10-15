import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import { nextTick } from 'vue';
import axios from 'axios';
import RequestPanel from './RequestPanel.vue';

/**
 * The panel that builds every outgoing request, including its credentials.
 *
 * Two invariants matter enough to pin. First, "Update" and "Save as new" must
 * produce the same request — they were separate code paths until this was
 * refactored to share one builder, and nothing checked they agreed. Second,
 * the auth config must only ever carry the fields of the scheme that is
 * actually selected: the panel keeps every scheme's inputs in one object, so a
 * careless collector would ship the password you typed before switching to
 * bearer.
 */
describe('RequestPanel', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        // The panel fetches nothing on mount, but discovery calls exist on
        // other paths; keep the network out of it either way.
        vi.spyOn(axios, 'get').mockResolvedValue({ data: [] });
        vi.spyOn(axios, 'post').mockResolvedValue({ data: {} });
    });

    const panel = (props = {}) => mount(RequestPanel, {
        props: { isLoading: false, ...props },
        global: { stubs: { ExportMenu: true, GraphqlSchema: true } },
    });

    /** Fill in the URL, since every save path requires one. */
    async function withUrl(wrapper, url = 'https://api.example.com/users') {
        await wrapper.find('input[type="text"], input:not([type])').setValue(url);

        return wrapper;
    }

    async function saveAsNew(wrapper, name = 'Saved name') {
        await wrapper.findAll('button').find((b) => /Save Request|Save as new/.test(b.text())).trigger('click');
        await wrapper.find('.save-row input').setValue(name);
        await wrapper.findAll('.save-row button').find((b) => b.text() === 'Save').trigger('click');

        return wrapper.emitted('save-request')?.at(-1)?.[0];
    }

    it('builds a REST save payload from the form', async () => {
        const wrapper = await withUrl(panel());

        const payload = await saveAsNew(wrapper, 'List users');

        expect(payload).toMatchObject({
            name: 'List users',
            protocol: 'rest',
            url: 'https://api.example.com/users',
        });
    });

    it('emits the same request for Update as for Save as new', async () => {
        // The invariant created by sharing one builder between the two paths.
        const loaded = { id: 42, name: 'Existing', protocol: 'rest', method: 'GET', url: 'https://api.example.com/users' };

        const wrapper = panel({ loadedRequest: loaded });
        await nextTick();

        await wrapper.findAll('button').find((b) => b.text() === 'Update').trigger('click');
        const update = wrapper.emitted('update-request')?.at(-1)?.[0];

        expect(update.id).toBe(42);

        const saved = await saveAsNew(wrapper, 'Existing');

        // Same request, differing only in which event carried it.
        expect(update.payload).toEqual(saved);
    });

    it('sends no auth at all when none is selected', async () => {
        const wrapper = await withUrl(panel());

        expect((await saveAsNew(wrapper)).auth).toBe(null);
    });

    describe('auth collection', () => {
        /** Open the Auth tab and choose a scheme. */
        async function chooseScheme(wrapper, scheme) {
            await wrapper.findAll('.tab').find((t) => t.text().startsWith('Auth')).trigger('click');
            await wrapper.find('.auth-scheme').setValue(scheme);
            await nextTick();
        }

        it('carries only the fields of the selected scheme', async () => {
            const wrapper = await withUrl(panel());

            await chooseScheme(wrapper, 'basic');
            const inputs = wrapper.findAll('.tab-content input.input-field');
            await inputs[0].setValue('ada');
            await inputs[1].setValue('a-password');

            // Switch scheme without clearing the form, as a user would.
            await chooseScheme(wrapper, 'bearer');
            await wrapper.find('.tab-content input.mono').setValue('a-token');

            const { auth } = await saveAsNew(wrapper);

            expect(auth).toEqual({ scheme: 'bearer', token: 'a-token' });
            // The password typed a moment ago must not travel with it.
            expect(JSON.stringify(auth)).not.toContain('a-password');
        });

        it('sends nothing for a protocol that has no HTTP auth', async () => {
            // gRPC, MQTT and AMQP carry credentials in their own connection
            // params; an auth header would be meaningless there.
            const wrapper = await withUrl(panel());

            await chooseScheme(wrapper, 'bearer');
            await wrapper.find('.tab-content input.mono').setValue('a-token');

            await wrapper.find('select.proto-select, select').setValue('mqtt');
            await nextTick();

            const payload = await saveAsNew(wrapper);
            expect(payload.auth).toBeUndefined();
            expect(JSON.stringify(payload)).not.toContain('a-token');
        });
    });
});
