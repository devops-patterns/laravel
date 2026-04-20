import { Head, router, useForm, usePage, usePoll } from '@inertiajs/react';
import { Loader2, Plus, Rocket, Server, Trash2 } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index, store, storeBatch, destroy } from '@/routes/tasks';
import type { Task, TaskStatus } from '@/types';

type Props = {
    tasks: Task[];
};

const statusConfig: Record<TaskStatus, { label: string; variant: 'default' | 'secondary' | 'destructive' | 'outline' }> = {
    pending: { label: 'Pending', variant: 'outline' },
    processing: { label: 'Processing', variant: 'default' },
    completed: { label: 'Completed', variant: 'secondary' },
    failed: { label: 'Failed', variant: 'destructive' },
};

function ProgressBar({ status, progress }: { status: TaskStatus; progress: number }) {
    const bgColor = {
        pending: 'bg-muted-foreground/30',
        processing: 'bg-primary',
        completed: 'bg-green-500',
        failed: 'bg-destructive',
    }[status];

    return (
        <div className="flex items-center gap-2">
            <div className="h-2 w-24 overflow-hidden rounded-full bg-muted">
                <div
                    className={`h-full rounded-full transition-all duration-500 ${bgColor}`}
                    style={{ width: `${progress}%` }}
                />
            </div>
            <span className="text-xs tabular-nums text-muted-foreground">{progress}%</span>
        </div>
    );
}

export default function TasksIndex({ tasks }: Props) {
    const page = usePage();
    const { hostname, currentTeam } = page.props;
    const [open, setOpen] = useState(false);

    usePoll(2500);

    const form = useForm({
        name: '',
        description: '',
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        if (!currentTeam) return;

        form.post(store.url(currentTeam.slug), {
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
        });
    }

    function handleBatch() {
        if (!currentTeam) return;

        router.post(storeBatch.url(currentTeam.slug));
    }

    function handleDelete(taskId: number) {
        if (!currentTeam) return;

        router.delete(destroy.url({ current_team: currentTeam.slug, task: taskId }));
    }

    const hasProcessing = tasks.some((t) => t.status === 'processing');

    return (
        <>
            <Head title="Tasks" />

            <h1 className="sr-only">Tasks</h1>

            <div className="flex flex-col space-y-6 p-4 lg:p-6">
                <div className="flex items-center justify-between">
                    <Heading
                        variant="small"
                        title="Tasks"
                        description="Dispatch long-running jobs to test queue processing and graceful shutdown"
                    />

                    <div className="flex items-center gap-3">
                        <Badge variant="outline" className="gap-1.5 font-mono text-xs">
                            <Server className="size-3" />
                            {hostname}
                        </Badge>

                        <Button variant="outline" onClick={handleBatch}>
                            <Rocket /> Batch x10
                        </Button>

                        <Dialog open={open} onOpenChange={setOpen}>
                            <DialogTrigger asChild>
                                <Button>
                                    <Plus /> Dispatch Task
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>Dispatch New Task</DialogTitle>
                                    <DialogDescription>
                                        Creates a task and dispatches a job that runs for 30-60 seconds.
                                    </DialogDescription>
                                </DialogHeader>
                                <form onSubmit={handleSubmit} className="space-y-4">
                                    <div className="space-y-2">
                                        <Label htmlFor="name">Name</Label>
                                        <Input
                                            id="name"
                                            value={form.data.name}
                                            onChange={(e) => form.setData('name', e.target.value)}
                                            placeholder="e.g. Data processing batch"
                                            required
                                        />
                                        {form.errors.name && (
                                            <p className="text-sm text-destructive">{form.errors.name}</p>
                                        )}
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="description">Description (optional)</Label>
                                        <Input
                                            id="description"
                                            value={form.data.description}
                                            onChange={(e) => form.setData('description', e.target.value)}
                                            placeholder="What this task does..."
                                        />
                                        {form.errors.description && (
                                            <p className="text-sm text-destructive">{form.errors.description}</p>
                                        )}
                                    </div>
                                    <DialogFooter>
                                        <Button type="submit" disabled={form.processing}>
                                            {form.processing && <Loader2 className="animate-spin" />}
                                            Dispatch
                                        </Button>
                                    </DialogFooter>
                                </form>
                            </DialogContent>
                        </Dialog>
                    </div>
                </div>

                {hasProcessing && (
                    <div className="flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-4 py-2 text-sm text-blue-700 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-300">
                        <Loader2 className="size-4 animate-spin" />
                        Jobs are being processed. Page auto-refreshes every 2.5s.
                    </div>
                )}

                <div className="overflow-hidden rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="border-b bg-muted/50">
                            <tr>
                                <th className="px-4 py-3 text-left font-medium">Name</th>
                                <th className="px-4 py-3 text-left font-medium">Status</th>
                                <th className="px-4 py-3 text-left font-medium">Progress</th>
                                <th className="px-4 py-3 text-left font-medium">Worker</th>
                                <th className="px-4 py-3 text-left font-medium">Created</th>
                                <th className="px-4 py-3 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {tasks.map((task) => (
                                <tr key={task.id} className="hover:bg-muted/30">
                                    <td className="px-4 py-3">
                                        <div>
                                            <div className="font-medium">{task.name}</div>
                                            {task.description && (
                                                <div className="text-xs text-muted-foreground">{task.description}</div>
                                            )}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">
                                        <Badge variant={statusConfig[task.status].variant}>
                                            {task.status === 'processing' && (
                                                <Loader2 className="size-3 animate-spin" />
                                            )}
                                            {statusConfig[task.status].label}
                                        </Badge>
                                    </td>
                                    <td className="px-4 py-3">
                                        <ProgressBar status={task.status} progress={task.progress} />
                                    </td>
                                    <td className="px-4 py-3">
                                        {task.workerHostname ? (
                                            <span className="font-mono text-xs">{task.workerHostname}</span>
                                        ) : (
                                            <span className="text-xs text-muted-foreground">—</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-xs text-muted-foreground">
                                        {new Date(task.createdAt).toLocaleString()}
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => handleDelete(task.id)}
                                            disabled={task.status === 'processing'}
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                            {tasks.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="px-4 py-12 text-center text-muted-foreground">
                                        No tasks yet. Dispatch one to see queue processing in action.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

TasksIndex.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Tasks',
            href: props.currentTeam ? index.url(props.currentTeam.slug) : '/',
        },
    ],
});
