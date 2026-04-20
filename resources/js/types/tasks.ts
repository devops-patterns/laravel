export type TaskStatus = 'pending' | 'processing' | 'completed' | 'failed';

export type Task = {
    id: number;
    name: string;
    description: string | null;
    status: TaskStatus;
    progress: number;
    workerHostname: string | null;
    startedAt: string | null;
    completedAt: string | null;
    createdAt: string;
};
