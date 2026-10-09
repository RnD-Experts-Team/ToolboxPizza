<?php

namespace Database\Seeders;

use App\Models\TicketSection;
use Illuminate\Database\Seeder;

/**
 * The section catalog the frontend tags areas with.
 *
 * These KEYS are hardcoded in the dashboard, so the rows have to exist before a
 * ticket can be raised from those screens - unlike levels and assignments, which
 * are org structure and belong to whoever administers the queues.
 *
 * The list mirrors b-dashboard-pizza/lib/report-problem/pages.ts: every key
 * allReportKeys() can send (each page in REPORT_PAGES, each region in
 * REGION_SECTION_KEYS, each card tag in ELEMENT_SECTION_KEYS, and the General
 * fallback). A key missing here makes "Report a problem" fall back to General
 * on that screen; Tickets -> Admin -> Coverage shows the same comparison live.
 * Add a key here whenever one is added there.
 *
 * Idempotent on `key`, and it deliberately does NOT touch `active`: retiring a
 * section is an admin decision, and re-running a seeder must not quietly undo
 * it. Only the wording and the picker order are refreshed.
 */
class TicketSectionSeeder extends Seeder
{
    public function run(): void
    {
        $order = 0;

        foreach ($this->catalog() as $key => [$name, $description]) {
            $order += 10;

            $section = TicketSection::query()->firstOrNew(['key' => $key]);

            $section->fill([
                'name' => $name,
                'description' => $description,
                'display_order' => $order,
            ]);

            // Only on first insert. An existing row keeps whatever the admin set.
            if (! $section->exists) {
                $section->active = true;
            }

            $section->save();
        }
    }

    /**
     * In picker order: General first, then the dashboard's sidebar groups.
     *
     * @return array<string, array{0: string,1: string}>
     */
    private function catalog(): array
    {
        return [
            // ── Everywhere ───────────────────────────────────────────────────
            'app.general' => [
                'General',
                'Anything that fits no other area. Reports from pages with no area of their own land here.',
            ],
            'app.navigation' => [
                'Navigation (sidebar, top bar, bottom bar)',
                'The sidebar, the top bar and the mobile bottom bar, on any page.',
            ],

            // ── Dashboards and reports ───────────────────────────────────────
            'dspr.dashboard' => [
                'DSPR dashboard',
                'The main dashboard (DSPR) and Dashboard V1.',
            ],
            // Boxes 3, 4 and 7 of the main dashboard all report here: they are
            // one problem to whoever fixes them, however many tiles they occupy.
            'inventory.main-dashboard' => [
                'Main dashboard - inventory',
                'Inventory boxes on the main dashboard.',
            ],
            'labor.dashboard' => [
                'Labor dashboard',
                'The labor dashboard.',
            ],
            'reports.business' => [
                'Business reports',
                'Business reports.',
            ],
            'reports.wbr' => [
                'WBR reports',
                'Weekly business review reports.',
            ],
            'reports.custom' => [
                'Custom reports',
                'Custom reports.',
            ],

            // ── Pages without a group ────────────────────────────────────────
            'announcements.page' => [
                'Announcements',
                'The announcements page.',
            ],
            'screens.menu' => [
                'Menu screens',
                'In-store menu boards and the screens app.',
            ],
            'scheduling.page' => [
                'Scheduling',
                'The weekly schedule, time clock and published weeks.',
            ],

            // ── Maintenance ──────────────────────────────────────────────────
            'maintenance.tickets' => [
                'Maintenance tickets',
                'Maintenance tickets: the list and a ticket\'s page.',
            ],
            'maintenance.requests' => [
                'Maintenance requests',
                'The older maintenance requests page.',
            ],
            'maintenance.analytics' => [
                'Maintenance analytics',
                'Maintenance analytics.',
            ],
            'maintenance.troubleshooting' => [
                'Troubleshooting',
                'Troubleshooting guides: the library and an issue\'s guides.',
            ],
            'maintenance.technicians' => [
                'Technicians',
                'The technicians report and a technician\'s page.',
            ],
            'maintenance.daily-pay' => [
                'Daily pay',
                'Daily pay sheets.',
            ],
            'maintenance.storage' => [
                'Storage & stock',
                'Storage locations, stock movements and balances.',
            ],
            'maintenance.sensors' => [
                'Sensors',
                'Store sensors.',
            ],

            // ── QA ───────────────────────────────────────────────────────────
            'qa.cleaning-chart' => [
                'Cleaning chart',
                'The cleaning chart: due tasks, evaluations and reports.',
            ],
            'qa.dough-sauce' => [
                'Dough & sauce',
                'Dough & sauce plans, the weekly grid and recipes.',
            ],
            'qa.page' => [
                'Quality assurance',
                'Camera audits, the camera report, and entities & categories.',
            ],

            // ── Data ─────────────────────────────────────────────────────────
            'data.keys' => [
                'Keys',
                'Data keys.',
            ],
            'data.debriefs' => [
                'Debriefs',
                'Due keys, employee debriefs and their history, and the floating Debrief button.',
            ],
            'data.export-import' => [
                'Export / import',
                'Data export and import.',
            ],
            'data.tags' => [
                'Tags',
                'Tags.',
            ],
            'data.goals' => [
                'Goals',
                'Store goals.',
            ],

            // ── Employees ────────────────────────────────────────────────────
            'hiring.page' => [
                'Hiring page',
                'The hiring dashboard itself.',
            ],
            'hiring.employees' => [
                'Employees',
                'The employees list and an employee\'s profile.',
            ],
            'hiring.tickets' => [
                'Hiring tickets',
                'Tickets raised from inside the hiring workflow.',
            ],

            // ── Inventory ────────────────────────────────────────────────────
            'inventory.management' => [
                'Inventory management',
                'Inventory units, items, count links and entries.',
            ],

            // ── Toolbox ──────────────────────────────────────────────────────
            'toolbox.workbooks' => [
                'Workbooks',
                'Workbooks.',
            ],
            'toolbox.breaks' => [
                'Breaks',
                'The break timer and break log.',
            ],
            'toolbox.tickets' => [
                'Tickets',
                'Tickets: the inbox, store queues and the admin catalog.',
            ],

            // ── Administration ───────────────────────────────────────────────
            'admin.stores' => [
                'Store management',
                'Stores and user-store assignments.',
            ],
            'admin.users' => [
                'User management',
                'Users, roles, permissions and assignments.',
            ],
            'admin.access' => [
                'Access rules & hierarchy',
                'Auth rules, the role hierarchy and service clients.',
            ],
            'app.settings' => [
                'Settings',
                'Personal settings, themes and preferences.',
            ],
            'app.dev-tools' => [
                'Developer tools',
                'Developer tools.',
            ],
        ];
    }
}
