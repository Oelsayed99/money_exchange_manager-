import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useTranslations } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface Change {
    field: string;
    old: string | null;
    new: string | null;
}

interface Row {
    id: number;
    recorded_at: string;
    event: string;
    event_label: string;
    type: string;
    record_id: number;
    actor: string | null;
    source: string;
    ip_address: string | null;
    changes: Change[];
}

interface Filters {
    event: string | null;
    type: string | null;
    user: number | null;
    from: string | null;
    to: string | null;
    search: string | null;
}

interface Props {
    logs: {
        data: Row[];
        links: { prev: string | null; next: string | null };
        meta: { total: number; from: number | null; to: number | null; current_page: number; last_page: number };
    };
    filters: Filters;
    options: {
        events: { value: string; label: string }[];
        types: { value: string; label: string }[];
        users: { id: number; name: string }[];
    };
}

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 rounded-md border px-3 py-1 text-sm focus-visible:ring-1 focus-visible:outline-none';

export default function AuditIndex({ logs, filters, options }: Props) {
    const { t } = useTranslations();

    const breadcrumbs: BreadcrumbItem[] = [{ title: t('audit.title'), href: '/audit' }];

    const apply = (changes: Partial<Filters>) =>
        router.get('/audit', clean({ ...filters, ...changes }), { preserveState: true, preserveScroll: true });

    const [search, setSearch] = useState(filters.search ?? '');

    useEffect(() => {
        if (search === (filters.search ?? '')) {
            return;
        }

        const timer = setTimeout(() => apply({ search: search === '' ? null : search }), 350);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const filtered = Object.values(filters).some((value) => value !== null && value !== '');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('audit.title')} />

            <div className="flex flex-col gap-6 p-4">
                <div className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight">{t('audit.title')}</h1>
                    <p className="text-muted-foreground max-w-2xl text-sm">{t('audit.description')}</p>
                </div>

                <div className="flex flex-wrap items-end gap-3">
                    <Field label={t('audit.filters.event')} htmlFor="event">
                        <select
                            id="event"
                            value={filters.event ?? ''}
                            onChange={(e) => apply({ event: e.target.value === '' ? null : e.target.value })}
                            className={selectClass}
                        >
                            <option value="">{t('audit.filters.all')}</option>
                            {options.events.map((event) => (
                                <option key={event.value} value={event.value}>
                                    {event.label}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field label={t('audit.filters.type')} htmlFor="type">
                        <select
                            id="type"
                            value={filters.type ?? ''}
                            onChange={(e) => apply({ type: e.target.value === '' ? null : e.target.value })}
                            className={selectClass}
                        >
                            <option value="">{t('audit.filters.all')}</option>
                            {options.types.map((type) => (
                                <option key={type.value} value={type.value}>
                                    {type.label}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field label={t('audit.filters.user')} htmlFor="user">
                        <select
                            id="user"
                            value={filters.user ?? ''}
                            onChange={(e) => apply({ user: e.target.value === '' ? null : Number(e.target.value) })}
                            className={selectClass}
                        >
                            <option value="">{t('audit.filters.all')}</option>
                            {options.users.map((user) => (
                                <option key={user.id} value={user.id}>
                                    {user.name}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field label={t('audit.filters.from')} htmlFor="from">
                        <Input
                            id="from"
                            type="date"
                            dir="ltr"
                            className="w-40"
                            value={filters.from ?? ''}
                            onChange={(e) => apply({ from: e.target.value === '' ? null : e.target.value })}
                        />
                    </Field>

                    <Field label={t('audit.filters.to')} htmlFor="to">
                        <Input
                            id="to"
                            type="date"
                            dir="ltr"
                            className="w-40"
                            value={filters.to ?? ''}
                            onChange={(e) => apply({ to: e.target.value === '' ? null : e.target.value })}
                        />
                    </Field>

                    <Field label={t('audit.filters.search')} htmlFor="search">
                        <Input id="search" type="search" className="w-56" value={search} onChange={(e) => setSearch(e.target.value)} />
                    </Field>

                    {filtered && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                setSearch('');
                                router.get('/audit', {}, { preserveScroll: true });
                            }}
                        >
                            {t('audit.filters.clear')}
                        </Button>
                    )}
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full min-w-3xl text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr>
                                <th scope="col" className="p-2 text-start font-medium">
                                    {t('audit.columns.when')}
                                </th>
                                <th scope="col" className="p-2 text-start font-medium">
                                    {t('audit.columns.who')}
                                </th>
                                <th scope="col" className="p-2 text-start font-medium">
                                    {t('audit.columns.what')}
                                </th>
                                <th scope="col" className="p-2 text-start font-medium">
                                    {t('audit.columns.change')}
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            {logs.data.length === 0 && (
                                <tr>
                                    <td colSpan={4} className="text-muted-foreground p-6 text-center">
                                        {t('audit.none')}
                                    </td>
                                </tr>
                            )}

                            {logs.data.map((row) => (
                                <tr key={row.id} className="border-t align-top">
                                    <td className="p-2 whitespace-nowrap tabular-nums" dir="ltr">
                                        {row.recorded_at}
                                    </td>
                                    <td className="p-2">
                                        {/* The label stored on the row, not a lookup: it is a
                                            snapshot from the time, and it outlives the account. */}
                                        <div>{row.actor ?? t('audit.system')}</div>
                                        <div className="text-muted-foreground text-xs">
                                            {row.source === 'console' ? t('audit.console') : row.ip_address}
                                        </div>
                                    </td>
                                    <td className="p-2">
                                        <EventBadge event={row.event} label={row.event_label} />
                                        <div className="mt-1">{row.type}</div>
                                        <div className="text-muted-foreground text-xs" dir="ltr">
                                            {t('audit.record', { id: String(row.record_id) })}
                                        </div>
                                    </td>
                                    <td className="p-2">
                                        {row.changes.length === 0 ? (
                                            <span className="text-muted-foreground text-xs">{t('audit.no_changes')}</span>
                                        ) : (
                                            <dl className="space-y-1">
                                                {row.changes.map((change) => (
                                                    <div key={change.field} className="text-xs">
                                                        <dt className="text-muted-foreground inline">{change.field}: </dt>
                                                        <dd className="inline">
                                                            <Value text={change.old} muted />
                                                            <span className="text-muted-foreground mx-1">→</span>
                                                            <Value text={change.new} />
                                                        </dd>
                                                    </div>
                                                ))}
                                            </dl>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-muted-foreground text-xs">
                        {logs.meta.total > 0 &&
                            t('audit.showing', {
                                from: String(logs.meta.from ?? 0),
                                to: String(logs.meta.to ?? 0),
                                total: String(logs.meta.total),
                            })}
                    </p>

                    <div className="flex gap-2">
                        <PageLink href={logs.links.prev} label={t('audit.previous')} />
                        <PageLink href={logs.links.next} label={t('audit.next')} />
                    </div>
                </div>

                <div className="text-muted-foreground space-y-1 text-xs">
                    <p>{t('audit.append_only')}</p>
                    <p>{t('audit.redaction')}</p>
                </div>
            </div>
        </AppLayout>
    );
}

/** A stored value, with "absent" and "empty string" told apart. */
function Value({ text, muted = false }: { text: string | null; muted?: boolean }) {
    const { t } = useTranslations();

    if (text === null) {
        return <span className="text-muted-foreground italic">{t('audit.nothing')}</span>;
    }

    if (text === '') {
        return <span className="text-muted-foreground italic">{t('audit.empty')}</span>;
    }

    return <span className={cn('font-mono', muted && 'text-muted-foreground line-through')}>{text}</span>;
}

function EventBadge({ event, label }: { event: string; label: string }) {
    const tone: Record<string, string> = {
        created: 'border-emerald-600/40 bg-emerald-600/10 text-emerald-800 dark:text-emerald-300',
        updated: 'border-sky-600/40 bg-sky-600/10 text-sky-800 dark:text-sky-300',
        deleted: 'border-red-600/40 bg-red-600/10 text-red-800 dark:text-red-300',
        restored: 'border-amber-600/40 bg-amber-600/10 text-amber-800 dark:text-amber-300',
    };

    return <span className={cn('rounded-md border px-2 py-0.5 text-xs whitespace-nowrap', tone[event])}>{label}</span>;
}

function PageLink({ href, label }: { href: string | null; label: string }) {
    return (
        <Button variant="outline" size="sm" disabled={href === null} asChild={href !== null}>
            {href === null ? (
                <span>{label}</span>
            ) : (
                <Link href={href} preserveScroll>
                    {label}
                </Link>
            )}
        </Button>
    );
}

function Field({ label, htmlFor, children }: { label: string; htmlFor: string; children: React.ReactNode }) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={htmlFor} className="text-xs">
                {label}
            </Label>
            {children}
        </div>
    );
}

function clean(filters: Filters): Record<string, string> {
    const query: Record<string, string> = {};

    for (const [key, value] of Object.entries(filters)) {
        if (value !== null && value !== '') {
            query[key] = String(value);
        }
    }

    return query;
}
