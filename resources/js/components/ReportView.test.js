import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import ReportView from './ReportView.vue';

/**
 * Report rendering, where two real defects have already lived.
 *
 * Nine of the fifteen report types had no branch at all and rendered an empty
 * modal — including collection_run, the most common one. And the first cut of
 * the fuzz renderer coloured every verdict except "ok" as a failure, which
 * would have reported a clean fuzz run as failing, because "rejected" means
 * the API correctly refused bad input.
 */
describe('ReportView', () => {
    const render = (type, data) => mount(ReportView, { props: { type, data } });

    describe('fuzz verdicts', () => {
        // Mirrors FuzzRunner: only these two increment the finding count.
        const fuzz = (verdict) => render('fuzz', {
            passed: true, total: 1, findings: 0, server_errors: 0, accepted_invalid: 0,
            results: [{ label: 'empty body', expects_reject: true, status: 400, verdict }],
        });

        it('treats a correct rejection as a pass, not a failure', () => {
            // The near-miss. "rejected" is the API doing its job.
            const row = fuzz('rejected').find('.rv-row');

            expect(row.classes()).toContain('st-pass');
            expect(row.classes()).not.toContain('st-fail');
        });

        it('treats an accepted invalid input as a failure', () => {
            expect(fuzz('accepted_invalid').find('.rv-row').classes()).toContain('st-fail');
        });

        it('treats a server error as a failure', () => {
            expect(fuzz('server_error').find('.rv-row').classes()).toContain('st-fail');
        });

        it('treats a transport error as neither', () => {
            const classes = fuzz('error').find('.rv-row').classes();

            expect(classes).toContain('st-warn');
            expect(classes).not.toContain('st-fail');
        });
    });

    describe('collection runs', () => {
        const run = {
            passed: false, total: 2, passed_count: 1, skipped_count: 0, time_ms: 120,
            collection: { id: 1, name: 'Checkout suite' },
            environment: { id: 2, name: 'Staging' },
            steps: [
                { name: 'Fetch user', method: 'GET', status: 200, time_ms: 40, passed: true, skipped: false },
                {
                    name: 'Create order', method: 'POST', status: 500, time_ms: 80,
                    passed: false, skipped: false, unresolved: ['token'],
                    assertions: { passed: false, results: [
                        { path: 'status', operator: 'equals', expected: '200', actual: 500, passed: false },
                        { path: '$.id', operator: 'exists', expected: null, actual: 1, passed: true },
                    ] },
                },
            ],
        };

        it('renders a row per step with its outcome', () => {
            const rows = render('collection_run', run).findAll('.rv-row');

            expect(rows).toHaveLength(2);
            expect(rows[0].classes()).toContain('st-pass');
            expect(rows[1].classes()).toContain('st-fail');
        });

        it('spells out the failed assertion and the unresolved variable', () => {
            const text = render('collection_run', run).text();

            expect(text).toContain('status equals 200');
            expect(text).toContain('actual: 500');
            expect(text).toContain('Unresolved: token');
            // The passing assertion is not noise in a failure.
            expect(text).not.toContain('$.id');
        });

        it('does not render a step skipped after an earlier failure as failed', () => {
            const rows = render('collection_run', {
                ...run,
                steps: [{ name: 'Later step', passed: false, skipped: true, error: 'Skipped after an earlier step failed.' }],
            }).findAll('.rv-row');

            expect(rows[0].classes()).toContain('st-skip');
            expect(rows[0].text()).toContain('skip');
        });
    });

    describe('types with no dedicated branch', () => {
        it('still renders something rather than an empty modal', () => {
            // The original defect: no branch meant a header, some buttons, and
            // nothing else. A new report type must be legible on day one.
            const wrapper = render('some_future_type', {
                passed: false, findings: 3, note: 'a scalar field', nested: { ignored: true },
            });

            const text = wrapper.text();
            expect(text).toContain('findings');
            expect(text).toContain('3');
            expect(text).toContain('a scalar field');
            // …and the whole record is still reachable.
            expect(wrapper.find('.rv-pre').exists()).toBe(true);
        });

        it('does not fall through to the generic view for a known type', () => {
            expect(render('collection_run', { steps: [], total: 0, passed_count: 0 }).find('.rv-pre').exists())
                .toBe(false);
        });
    });
});
