import axios from 'axios';
import { toast } from 'vue-sonner';

/**
 * A 419 means the server has no session matching this browser's cookies, so
 * every POST keeps failing until the page gets a fresh CSRF token. Without
 * this, the pages only console.error and the operator sees nothing while
 * scores silently stop saving mid-match.
 */
export function installSessionGuard(): void {
    let recovering = false;

    axios.interceptors.response.use(
        (response) => response,
        (error) => {
            if (error?.response?.status === 419 && !recovering) {
                recovering = true;

                toast.error('Sesi login berakhir. Memuat ulang halaman...', {
                    duration: 4000,
                });

                window.setTimeout(() => window.location.reload(), 1500);
            }

            return Promise.reject(error);
        },
    );
}
