import type { Auth } from '@/types/auth';
import type { Team } from '@/types/teams';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            hostname: string;
            sidebarOpen: boolean;
            currentTeam: Team | null;
            teams: Team[];
            [key: string]: unknown;
        };
    }
}
